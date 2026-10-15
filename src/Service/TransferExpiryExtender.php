<?php

declare(strict_types=1);

namespace App\Service;

use App\ValueObject\UnsignedTransfer;
use IEXBase\TronAPI\Encoding\Hex;
use IEXBase\TronAPI\Transaction\Transaction;
use IEXBase\TronAPI\Transaction\TransactionWireEncoder;

/**
 * Extends the expiration of a node-built transaction without touching its
 * contract.
 *
 * Used by the settlement flow: the node returns a transaction that expires in
 * about a minute, but an operator needs hours to carry it to the air-gapped
 * machine, sign it and bring it back. Only `expiration` is rewritten; the
 * contract bytes are taken from the locally verified intent, so a malicious or
 * buggy node cannot smuggle a different recipient or amount through.
 */
final readonly class TransferExpiryExtender
{
    public function __construct(
        private TransactionWireEncoder $encoder = new TransactionWireEncoder(),
    ) {
    }

    public function extend(
        Transaction $transaction,
        string $fromAddress,
        string $toAddress,
        int $amountSun,
        \DateTimeImmutable $expiresAt,
    ): UnsignedTransfer {
        $expirationMilliseconds = (int) $expiresAt->format('Uv');

        $rawData = $transaction->rawData();
        $rawData['expiration'] = $expirationMilliseconds;
        $rawDataHex = Hex::fromBytes($this->encoder->encode($transaction->approvedIntent(), $rawData));

        // Rebuild from the new bytes so txID and raw_data_hex stay consistent.
        $extended = Transaction::fromNodeData([
            'raw_data' => $rawData,
            'raw_data_hex' => $rawDataHex,
            'txID' => hash('sha256', Hex::toBytes($rawDataHex)),
        ]);

        return new UnsignedTransfer(
            txId: $extended->id(),
            fromAddress: $fromAddress,
            toAddress: $toAddress,
            amountSun: $amountSun,
            expirationMilliseconds: $expirationMilliseconds,
            rawData: $extended->rawData(),
            rawDataHex: $extended->rawDataHex(),
        );
    }
}
