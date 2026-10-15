<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TransferExpiryExtender;
use App\Tests\Helper\TronTransactionFixture;
use IEXBase\TronAPI\Crypto\LocalPrivateKeySigner;
use IEXBase\TronAPI\Transaction\Transaction;
use PHPUnit\Framework\TestCase;

/**
 * The settlement flow extends the ~60s node expiration so an operator can sign
 * offline. This must not alter the contract, only the expiry.
 */
final class TransferExpiryExtenderTest extends TestCase
{
    private const AMOUNT_SUN = 10_000_000;

    public function testExtendsExpirationAndKeepsTheVerifiedContract(): void
    {
        $owner = LocalPrivateKeySigner::generate();
        $recipient = LocalPrivateKeySigner::generate();
        $ownerAddress = $owner->address()->toBase58();
        $recipientAddress = $recipient->address()->toBase58();

        $nodeTransaction = TronTransactionFixture::nodeTransaction(
            $ownerAddress,
            $recipientAddress,
            self::AMOUNT_SUN,
            (int) (new \DateTimeImmutable('+1 minute'))->format('Uv'),
        );
        $expiresAt = (new \DateTimeImmutable())->modify('+12 hours');

        $unsigned = (new TransferExpiryExtender())->extend(
            $nodeTransaction,
            $ownerAddress,
            $recipientAddress,
            self::AMOUNT_SUN,
            $expiresAt,
        );

        self::assertSame((int) $expiresAt->format('Uv'), $unsigned->expirationMilliseconds);
        self::assertSame($ownerAddress, $unsigned->fromAddress);
        self::assertSame($recipientAddress, $unsigned->toAddress);
        self::assertSame(self::AMOUNT_SUN, $unsigned->amountSun);
        self::assertNotSame($nodeTransaction->id(), $unsigned->txId, 'The expiry is part of the signed bytes.');

        // The extended bytes must still describe the same approved contract:
        // re-approving the intent re-encodes raw_data and compares it byte for
        // byte with raw_data_hex.
        $rebuilt = Transaction::fromNodeData([
            'raw_data' => $unsigned->rawData,
            'raw_data_hex' => $unsigned->rawDataHex,
            'txID' => $unsigned->txId,
        ])->approveIntent(TronTransactionFixture::intent($ownerAddress, $recipientAddress, self::AMOUNT_SUN));

        self::assertSame($unsigned->txId, $rebuilt->id());
        self::assertFalse($rebuilt->isExpired());
    }
}
