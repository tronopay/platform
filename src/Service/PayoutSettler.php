<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PayoutFailureKind;
use App\Entity\PayoutOutcome;
use App\ValueObject\PayoutIntentBundle;
use App\ValueObject\PayoutReportBundle;
use App\ValueObject\PayoutReportItem;
use App\ValueObject\PayoutSettleResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The settlement machine: turns one intent batch into a report batch.
 *
 * This is the only place that holds the mnemonic and talks to the TRON node for
 * payouts. For every intent item it derives the escrow key, verifies the derived
 * address matches the intent's `fromAddress`, builds the transfer at the node
 * with a short fresh expiry, checks the escrow balance covers amount + fees,
 * signs, publishes immediately and reads back the receipt (turning `broadcast`
 * into `confirmed` when the contract matches the intent).
 *
 * There is no database here. A small journal next to the reports keeps the set
 * of payout ids already settled per batch, so re-running the same intent never
 * signs or broadcasts a transfer twice.
 */
final readonly class PayoutSettler
{
    public function __construct(
        private HdWalletMnemonicSigner $hdWallet,
        private OfflineTransferSigner $offlineSigner,
        private TronGatewayInterface $gateway,
        private PayoutBundleStorage $storage,
        #[Autowire('%env(TRON_NETWORK)%')]
        private string $network = 'mainnet',
        #[Autowire('%payout_ttl%')]
        private string $ttl = '30 minutes',
        #[Autowire('%int:payout_fee_margin%')]
        private int $feeMarginPercent = 20,
        private bool $journalEnabled = true,
    ) {
    }

    public function settle(
        PayoutIntentBundle $intent,
        \DateTimeImmutable $now,
        string $mnemonic,
        string $passphrase = '',
        bool $dryRun = false,
        string $reportPath = '',
    ): PayoutSettleResult {
        if ($intent->network !== $this->network) {
            throw new \RuntimeException(sprintf(
                'Refusing to settle a "%s" intent: this machine is configured for "%s".',
                $intent->network,
                $this->network,
            ));
        }

        $journalPath = $this->journalPath($reportPath, $intent->batchId);
        $processed = $this->journalEnabled ? $this->readJournal($journalPath) : [];

        $items = [];
        foreach ($intent->items as $item) {
            if ($this->journalEnabled && isset($processed[$item->payoutId])) {
                $items[] = $this->reportFromJournal($processed[$item->payoutId], $item->taskId);
                continue;
            }

            $reportItem = $this->settleItem($item->payoutId, $item->taskId, $item->direction, $item->walletIndex, $item->fromAddress, $item->toAddress, $item->amountSun, $mnemonic, $passphrase, $now, $dryRun);
            $items[] = $reportItem;

            if ($this->journalEnabled && !$dryRun) {
                $processed[$item->payoutId] = $this->journalEntry($reportItem);
                $this->writeJournal($journalPath, $processed);
            }
        }

        $report = new PayoutReportBundle(
            network: $intent->network,
            batchId: $intent->batchId,
            createdAt: $intent->createdAt,
            settledAt: $now,
            items: $items,
        );

        $reportPath = $this->resolveReportPath($reportPath, $intent->batchId);
        if (!$dryRun) {
            $this->storage->writeReportTo($reportPath, $report);
        }

        return new PayoutSettleResult($intent->batchId, $items, $dryRun ? null : $reportPath, $dryRun);
    }

    private function settleItem(
        string $payoutId,
        string $taskId,
        string $direction,
        int $walletIndex,
        string $fromAddress,
        string $toAddress,
        int $amountSun,
        string $mnemonic,
        string $passphrase,
        \DateTimeImmutable $now,
        bool $dryRun,
    ): PayoutReportItem {
        try {
            $signer = $this->hdWallet->signerFor($mnemonic, $walletIndex, $passphrase);
        } catch (\Throwable $exception) {
            return $this->fail($taskId, $payoutId, PayoutFailureKind::Permanent, sprintf('could not derive the wallet key: %s', $exception->getMessage()));
        }

        $derivedAddress = $signer->address()->toBase58();
        if ($derivedAddress !== $fromAddress) {
            return $this->fail($taskId, $payoutId, PayoutFailureKind::Permanent, sprintf(
                'derived address %s does not match the intent fromAddress %s',
                $derivedAddress,
                $fromAddress,
            ));
        }

        try {
            $transfer = $this->gateway->buildTransfer($fromAddress, $toAddress, $amountSun, $now->modify($this->ttl));
        } catch (\Throwable $exception) {
            return $this->fail($taskId, $payoutId, PayoutFailureKind::Retryable, sprintf('could not build the transfer: %s', $exception->getMessage()));
        }

        if ($transfer->fromAddress !== $fromAddress || $transfer->toAddress !== $toAddress || $transfer->amountSun !== $amountSun) {
            return $this->fail($taskId, $payoutId, PayoutFailureKind::Permanent, 'the node-built transfer does not match the intent');
        }

        try {
            $feeEstimate = $this->gateway->estimateFeeSun($fromAddress, $toAddress, $transfer);
        } catch (\Throwable $exception) {
            return $this->fail($taskId, $payoutId, PayoutFailureKind::Retryable, sprintf('could not estimate the fee: %s', $exception->getMessage()));
        }

        $margin = (int) round($feeEstimate * ($this->feeMarginPercent / 100));
        $required = $amountSun + $feeEstimate + $margin;
        try {
            $balance = $this->gateway->balanceSun($fromAddress);
        } catch (\Throwable $exception) {
            return $this->fail($taskId, $payoutId, PayoutFailureKind::Retryable, sprintf('could not read the escrow balance: %s', $exception->getMessage()));
        }

        if ($balance < $required) {
            return $this->fail($taskId, $payoutId, PayoutFailureKind::Retryable, sprintf(
                'the escrow wallet holds %d sun but %d are needed (amount %d + fee %d + margin %d)',
                $balance,
                $required,
                $amountSun,
                $feeEstimate,
                $margin,
            ));
        }

        try {
            $signed = $this->offlineSigner->sign($transfer, $signer);
        } catch (\Throwable $exception) {
            return $this->fail($taskId, $payoutId, PayoutFailureKind::Permanent, sprintf('could not sign the transfer: %s', $exception->getMessage()));
        }

        if ($signed->txId !== $transfer->txId) {
            return $this->fail($taskId, $payoutId, PayoutFailureKind::Permanent, 'signing changed the transaction id');
        }

        if ($signed->signerAddress !== $fromAddress) {
            return $this->fail($taskId, $payoutId, PayoutFailureKind::Permanent, 'the signature does not recover to the escrow wallet');
        }

        if ($dryRun) {
            return new PayoutReportItem($taskId, $payoutId, PayoutOutcome::Broadcast, txId: $transfer->txId);
        }

        $alreadyOnChain = false;
        try {
            $alreadyOnChain = $this->gateway->hasTransaction($transfer->txId);
        } catch (\Throwable) {
            $alreadyOnChain = false;
        }

        if (!$alreadyOnChain) {
            try {
                $this->gateway->broadcastSigned($signed->payload);
            } catch (\Throwable $exception) {
                if ($this->alreadyOnChain($transfer->txId)) {
                    $alreadyOnChain = true;
                } else {
                    return $this->fail($taskId, $payoutId, PayoutFailureKind::Retryable, sprintf('broadcast failed: %s', $exception->getMessage()));
                }
            }
        }

        return $this->confirmedOrBroadcast($taskId, $payoutId, $transfer->txId, $fromAddress, $toAddress, $amountSun);
    }

    private function confirmedOrBroadcast(
        string $taskId,
        string $payoutId,
        string $txId,
        string $fromAddress,
        string $toAddress,
        int $amountSun,
    ): PayoutReportItem {
        try {
            $receipt = $this->gateway->receipt($txId);
        } catch (\Throwable) {
            $receipt = null;
        }

        if (null === $receipt) {
            return new PayoutReportItem($taskId, $payoutId, PayoutOutcome::Broadcast, txId: $txId);
        }

        if (!$receipt->success) {
            return new PayoutReportItem($taskId, $payoutId, PayoutOutcome::Failed, txId: $txId, feeSun: $receipt->feeSun, blockNumber: $receipt->blockNumber, failureKind: PayoutFailureKind::Permanent, error: $receipt->message ?? $receipt->result ?? 'the transaction did not execute successfully');
        }

        try {
            $transfer = $this->gateway->findTransfer($txId);
        } catch (\Throwable) {
            $transfer = null;
        }

        // If we cannot yet confirm the executed contract matches the intent we
        // leave it as broadcast; a later apply-report pass will not upgrade it.
        if (null === $transfer || $transfer['from'] !== $fromAddress || $transfer['to'] !== $toAddress || $transfer['amountSun'] !== $amountSun) {
            return new PayoutReportItem($taskId, $payoutId, PayoutOutcome::Broadcast, txId: $txId, feeSun: $receipt->feeSun, blockNumber: $receipt->blockNumber);
        }

        return new PayoutReportItem($taskId, $payoutId, PayoutOutcome::Confirmed, txId: $txId, feeSun: $receipt->feeSun, blockNumber: $receipt->blockNumber);
    }

    private function fail(string $taskId, string $payoutId, PayoutFailureKind $kind, string $error): PayoutReportItem
    {
        return new PayoutReportItem($taskId, $payoutId, PayoutOutcome::Failed, failureKind: $kind, error: $error);
    }

    private function alreadyOnChain(string $txId): bool
    {
        try {
            return $this->gateway->hasTransaction($txId);
        } catch (\Throwable) {
            return false;
        }
    }

    private function reportFromJournal(array $entry, string $taskId): PayoutReportItem
    {
        return PayoutReportItem::fromArray([
            'taskId' => $taskId,
            'payoutId' => $entry['payoutId'],
            'outcome' => $entry['outcome'],
            'txId' => $entry['txId'] ?? null,
            'feeSun' => $entry['feeSun'] ?? null,
            'blockNumber' => $entry['blockNumber'] ?? null,
            'failureKind' => $entry['failureKind'] ?? null,
            'error' => $entry['error'] ?? null,
        ]);
    }

    private function journalEntry(PayoutReportItem $item): array
    {
        return $item->toArray();
    }

    private function journalPath(string $reportPath, string $batchId): string
    {
        if ('' !== $reportPath && str_contains($reportPath, '..')) {
            throw new \RuntimeException('The settlement report path must not contain "..".');
        }

        if ('' !== $reportPath) {
            $target = str_starts_with($reportPath, '/') ? $reportPath : $this->storage->directory().'/'.$reportPath;
            $directory = \dirname($target);
        } else {
            $directory = $this->storage->directory();
        }

        return rtrim($directory, '/').'/.'.$this->safeName($batchId).'.journal.json';
    }

    private function readJournal(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if (false === $json) {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    private function writeJournal(string $path, array $entries): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        @file_put_contents($path, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        @chmod($path, 0600);
    }

    private function resolveReportPath(string $reportPath, string $batchId): string
    {
        if ('' !== $reportPath) {
            return $reportPath;
        }

        return $this->storage->directory().'/'.$this->safeName($batchId).'.report.json';
    }

    private function safeName(string $batchId): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $batchId) ?? '';

        return $name !== '' ? $name : 'batch';
    }
}
