<?php

declare(strict_types=1);

namespace App\Service;

use App\ValueObject\PayoutReceipt;
use App\ValueObject\UnsignedTransfer;

/**
 * Abstraction over the TRON indexer and node so payment logic can be tested
 * without a node.
 *
 * Reading ({@see incomingTransfers}) and balance are used by the prepayment
 * watcher. The settlement flow builds an unsigned transfer on the machine,
 * signs it there and publishes it immediately; the gateway also lets the
 * machine read back the receipt and confirm the executed contract matches the
 * intent.
 */
interface TronGatewayInterface
{
    /**
     * Returns normalized incoming native TRX transfers received by $address in [$since, $until].
     *
     * @return list<array{txId: string, amount: int}>
     */
    public function incomingTransfers(string $address, \DateTimeImmutable $since, ?\DateTimeImmutable $until = null): array;

    /**
     * Builds an unsigned native TRX transfer with the given expiry.
     *
     * The node builds the protocol transaction; nothing is broadcast. Called on
     * the settlement machine so the ref_block / timestamp are always fresh.
     */
    public function buildTransfer(
        string $fromAddress,
        string $toAddress,
        int $amountSun,
        \DateTimeImmutable $expiresAt,
    ): UnsignedTransfer;

    /**
     * Broadcasts a signed transaction payload and returns its transaction id.
     *
     * @param array<string, mixed> $signedTransaction native signed transaction object
     */
    public function broadcastSigned(array $signedTransaction): string;

    /**
     * Whether the node already knows this transaction (confirmed or latest).
     */
    public function hasTransaction(string $txId): bool;

    /**
     * Confirmed native TRX balance of an account, in sun.
     */
    public function balanceSun(string $address): int;

    /**
     * Normalized execution receipt for a transaction, or null when absent.
     */
    public function receipt(string $txId): ?PayoutReceipt;

    /**
     * The executed native TRX transfer described by a transaction, or null when
     * the node does not know it yet or it is not a TransferContract.
     *
     * @return array{from: string, to: string, amountSun: int}|null
     */
    public function findTransfer(string $txId): ?array;

    /**
     * A conservative sun estimate of the fee to broadcast this transfer from
     * $fromAddress, given the built (unsigned) transfer.
     *
     * This is computed right before sending: available bandwidth, the chain
     * unit prices and the recipient activation fee are all read from the node
     * at that moment.
     */
    public function estimateFeeSun(string $fromAddress, string $toAddress, UnsignedTransfer $transfer): int;
}
