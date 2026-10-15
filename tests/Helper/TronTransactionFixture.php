<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\ValueObject\UnsignedTransfer;
use IEXBase\TronAPI\Encoding\Hex;
use IEXBase\TronAPI\Transaction\Transaction;
use IEXBase\TronAPI\Transaction\TransactionIntent;
use IEXBase\TronAPI\Transaction\TransactionWireEncoder;
use IEXBase\TronAPI\Value\Address;

/**
 * Builds native TRX transactions locally, exactly as a node would, so payout
 * code can be tested without a network.
 */
final class TronTransactionFixture
{
    public static function intent(string $ownerAddress, string $toAddress, int $amountSun): TransactionIntent
    {
        return new TransactionIntent('TransferContract', Address::fromBase58($ownerAddress), [
            'to_address' => Address::fromBase58($toAddress),
            'amount' => $amountSun,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rawData(string $ownerAddress, string $toAddress, int $amountSun, int $expirationMilliseconds): array
    {
        return [
            'ref_block_bytes' => 'abcd',
            'ref_block_hash' => '0123456789abcdef',
            'expiration' => $expirationMilliseconds,
            'timestamp' => (int) (new \DateTimeImmutable())->format('Uv'),
            'contract' => [[
                'type' => 'TransferContract',
                'parameter' => [
                    'type_url' => 'type.googleapis.com/protocol.TransferContract',
                    'value' => [
                        'owner_address' => $ownerAddress,
                        'to_address' => $toAddress,
                        'amount' => $amountSun,
                    ],
                ],
            ]],
        ];
    }

    /**
     * A transaction as the node returns it: approved intent attached, not signed.
     */
    public static function nodeTransaction(string $ownerAddress, string $toAddress, int $amountSun, int $expirationMilliseconds): Transaction
    {
        $intent = self::intent($ownerAddress, $toAddress, $amountSun);
        $rawData = self::rawData($ownerAddress, $toAddress, $amountSun, $expirationMilliseconds);
        $rawDataHex = Hex::fromBytes((new TransactionWireEncoder())->encode($intent, $rawData));

        return Transaction::fromNodeData([
            'raw_data' => $rawData,
            'raw_data_hex' => $rawDataHex,
            'txID' => hash('sha256', Hex::toBytes($rawDataHex)),
        ])->approveIntent($intent);
    }

    public static function unsignedTransfer(string $ownerAddress, string $toAddress, int $amountSun, int $expirationMilliseconds): UnsignedTransfer
    {
        $intent = self::intent($ownerAddress, $toAddress, $amountSun);
        $rawData = self::rawData($ownerAddress, $toAddress, $amountSun, $expirationMilliseconds);
        $rawDataHex = Hex::fromBytes((new TransactionWireEncoder())->encode($intent, $rawData));

        return new UnsignedTransfer(
            txId: hash('sha256', Hex::toBytes($rawDataHex)),
            fromAddress: $ownerAddress,
            toAddress: $toAddress,
            amountSun: $amountSun,
            expirationMilliseconds: $expirationMilliseconds,
            rawData: $rawData,
            rawDataHex: $rawDataHex,
        );
    }
}
