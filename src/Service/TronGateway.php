<?php

declare(strict_types=1);

namespace App\Service;

use App\ValueObject\PayoutReceipt;
use App\ValueObject\UnsignedTransfer;
use IEXBase\TronAPI\Enum\ConfirmationLevel;
use IEXBase\TronAPI\Indexer\PageRequest;
use IEXBase\TronAPI\Indexer\TimestampRange;
use IEXBase\TronAPI\Indexer\TransactionSearch;
use IEXBase\TronAPI\Transaction\Transaction;
use IEXBase\TronAPI\Transaction\TransactionCostCalculator;
use IEXBase\TronAPI\Value\Address;
use IEXBase\TronAPI\Value\Amount;

/**
 * Reads incoming transfers and builds/broadcasts native TRX transfers through
 * the configured TRON node.
 *
 * This gateway is used both by the online prepayment watcher and by the
 * settlement machine: the machine builds a transfer with a fresh expiry, signs
 * it, publishes it immediately and reads back the receipt to turn a broadcast
 * into a confirmed transfer.
 */
final readonly class TronGateway implements TronGatewayInterface
{
    private const MAX_PAGES = 5;

    public function __construct(
        private TronClientFactory $factory,
        private TransferExpiryExtender $expiryExtender = new TransferExpiryExtender(),
    ) {
    }

    public function incomingTransfers(string $address, \DateTimeImmutable $since, ?\DateTimeImmutable $until = null): array
    {
        $provider = $this->factory->create()->accountHistory();
        if ($provider === null) {
            return [];
        }
        $transfers = [];
        $cursor = null;
        $sinceMilliseconds = $since->getTimestamp() * 1000;
        $untilMilliseconds = $until == null ? null : $until->getTimestamp() * 1000;

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $search = new TransactionSearch(
                page: new PageRequest(limit: 200, cursor: $cursor),
                confirmed: true,
                incomingOnly: true,
                timestamps: new TimestampRange($sinceMilliseconds, $untilMilliseconds),
            );

            $result = $provider->transactions(Address::fromBase58($address), $search);
            foreach ($result->items() as $item) {
                $transfer = $this->parseTransfer($item, $address);
                if ($transfer !== null) {
                    $transfers[] = $transfer;
                }
            }

            $cursor = $result->nextCursor;
            if ($cursor === null) {
                break;
            }
        }

        return $transfers;
    }

    public function buildTransfer(
        string $fromAddress,
        string $toAddress,
        int $amountSun,
        \DateTimeImmutable $expiresAt,
    ): UnsignedTransfer {
        $tron = $this->factory->create();
        $transaction = $tron->transfers()->createTrxTransfer(
            Address::fromBase58($fromAddress),
            Address::fromBase58($toAddress),
            Amount::fromAtomic($amountSun),
        );

        return $this->expiryExtender->extend($transaction, $fromAddress, $toAddress, $amountSun, $expiresAt);
    }

    public function broadcastSigned(array $signedTransaction): string
    {
        $transaction = Transaction::fromNodeData($signedTransaction);

        $this->factory->create()->transactions()->broadcast($transaction);

        return $transaction->id();
    }

    public function hasTransaction(string $txId): bool
    {
        $transactions = $this->factory->create()->transactions();

        return $transactions->find($txId, ConfirmationLevel::Confirmed) !== null
            || $transactions->find($txId, ConfirmationLevel::Latest) !== null;
    }

    public function balanceSun(string $address): int
    {
        $account = $this->factory->create()->accounts()->get(Address::fromBase58($address));

        return $account?->balance->atomicInteger() ?? 0;
    }

    public function receipt(string $txId): ?PayoutReceipt
    {
        $receipt = $this->factory->create()->transactions()
            ->receipt($txId, ConfirmationLevel::Latest);

        if (null === $receipt) {
            return null;
        }

        return new PayoutReceipt(
            txId: $receipt->transactionId,
            success: $receipt->isSuccessful(),
            blockNumber: $receipt->blockNumber,
            feeSun: (int) $receipt->feeSun,
            result: $receipt->executionResult,
            message: $receipt->executionMessage,
        );
    }

    public function findTransfer(string $txId): ?array
    {
        $transaction = $this->factory->create()->transactions()
            ->find($txId, ConfirmationLevel::Latest);

        if (null === $transaction) {
            return null;
        }

        $contract = $transaction->rawData()['contract'][0] ?? null;
        if (!\is_array($contract) || ($contract['type'] ?? '') !== 'TransferContract') {
            return null;
        }

        $value = $contract['parameter']['value'] ?? null;
        if (!\is_array($value)) {
            return null;
        }

        $from = $this->addressToBase58($value['owner_address'] ?? null);
        $to = $this->addressToBase58($value['to_address'] ?? null);
        $amount = $value['amount'] ?? null;

        if (null === $from || null === $to || (!\is_int($amount) && !\is_string($amount))) {
            return null;
        }

        return ['from' => $from, 'to' => $to, 'amountSun' => (int) $amount];
    }

    public function estimateFeeSun(string $fromAddress, string $toAddress, UnsignedTransfer $transfer): int
    {
        $tron = $this->factory->create();

        $transaction = Transaction::fromNodeData([
            'raw_data' => $transfer->rawData,
            'raw_data_hex' => $transfer->rawDataHex,
            'txID' => $transfer->txId,
        ]);

        $bandwidthNeeded = (new TransactionCostCalculator())->bandwidth($transaction, 1);
        $bandwidthUnitPrice = $this->bandwidthUnitPriceSun();
        $availableBandwidth = $this->availableBandwidthSun($fromAddress);

        $fee = max(0, $bandwidthNeeded - $availableBandwidth) * $bandwidthUnitPrice;

        if (null === $tron->accounts()->get(Address::fromBase58($toAddress))) {
            $fee += $this->accountCreationFeeSun();
        }

        return $fee;
    }

    private function bandwidthUnitPriceSun(): int
    {
        $parameters = $this->factory->create()->network()->chainParameters();

        return (int) ($parameters['getTransactionFee'] ?? 0);
    }

    private function availableBandwidthSun(string $address): int
    {
        $account = $this->factory->create()->accounts()->bandwidth(Address::fromBase58($address));

        $freeLimit = $this->field($account, ['freeNetLimit', 'free_net_limit']);
        $freeUsed = $this->field($account, ['freeNetUsed', 'free_net_used']);
        $limit = $this->field($account, ['NetLimit', 'net_limit']);
        $used = $this->field($account, ['NetUsed', 'net_used']);

        return max(0, $limit - $used) + max(0, $freeLimit - $freeUsed);
    }

    private function accountCreationFeeSun(): int
    {
        $parameters = $this->factory->create()->network()->chainParameters();

        return (int) ($parameters['getCreateNewAccountFee'] ?? 0);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    private function field(array $data, array $keys): int
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && (\is_int($data[$key]) || \is_string($data[$key]) || \is_float($data[$key]))) {
                return (int) $data[$key];
            }
        }

        return 0;
    }

    private function addressToBase58(mixed $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        try {
            return Address::fromString($value)->toBase58();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{txId: string, amount: int}|null
     */
    private function parseTransfer(array $item, string $address): ?array
    {
        $contract = $item['raw_data']['contract'][0] ?? null;
        if (!\is_array($contract) || ($contract['type'] ?? '') !== 'TransferContract') {
            return null;
        }

        $value = $contract['parameter']['value'] ?? null;
        if (!\is_array($value)) {
            return null;
        }

        $toAddress = $value['to_address'] ?? null;
        $amount = $value['amount'] ?? null;
        $txId = $item['txID'] ?? null;

        if (!\is_string($toAddress) || !\is_string($txId)) {
            return null;
        }

        try {
            if (Address::fromString($toAddress)->toBase58() !== $address) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        if (!\is_int($amount) && !\is_string($amount)) {
            return null;
        }

        return ['txId' => $txId, 'amount' => (int) $amount];
    }
}
