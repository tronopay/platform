<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HdWalletMnemonicSigner;
use App\Service\OfflineTransferSigner;
use App\Tests\Helper\TronTransactionFixture;
use App\ValueObject\UnsignedTransfer;
use IEXBase\TronAPI\Transaction\Transaction;
use IEXBase\TronAPI\Value\Address;
use PHPUnit\Framework\TestCase;

final class OfflineTransferSignerTest extends TestCase
{
    private const MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
    private const ESCROW = 'TUEZSdKsoDHQMeZwihtdoBiN46zxhGWYdH';
    private const RECIPIENT = 'THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY';
    private const AMOUNT_SUN = 10_000_000;

    public function testSignsATransferThatTheChainWouldAccept(): void
    {
        $transfer = $this->transfer();
        $signer = (new HdWalletMnemonicSigner())->signerFor(self::MNEMONIC, 0);

        $signed = (new OfflineTransferSigner())->sign($transfer, $signer);

        self::assertSame(self::ESCROW, $signed->signerAddress);
        self::assertSame($transfer->txId, $signed->txId, 'Signing must not change the transaction id.');
        self::assertNotSame('', $signed->signature);

        // Simulate what app:payout:broadcast does with the returned payload.
        $rebuilt = Transaction::fromNodeData($signed->payload)->approveIntent(
            TronTransactionFixture::intent(self::ESCROW, self::RECIPIENT, self::AMOUNT_SUN),
        );

        self::assertTrue($rebuilt->isSigned());
        self::assertSame($transfer->txId, $rebuilt->id());
        self::assertSame(self::ESCROW, $rebuilt->signerAddresses()[0]->toBase58());
    }

    public function testRefusesAKeyThatDoesNotBelongToTheEscrowWallet(): void
    {
        $transfer = $this->transfer();
        $wrongSigner = (new HdWalletMnemonicSigner())->signerFor(self::MNEMONIC, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to sign/');
        (new OfflineTransferSigner())->sign($transfer, $wrongSigner);
    }

    public function testRefusesABundleWhoseBytesDoNotMatchTheStatedRecipient(): void
    {
        $transfer = $this->transfer();
        // Someone swapped the recipient in the bundle but kept the signed bytes.
        $tampered = new UnsignedTransfer(
            txId: $transfer->txId,
            fromAddress: $transfer->fromAddress,
            toAddress: Address::fromBase58(self::ESCROW)->toBase58(),
            amountSun: $transfer->amountSun,
            expirationMilliseconds: $transfer->expirationMilliseconds,
            rawData: $transfer->rawData,
            rawDataHex: $transfer->rawDataHex,
        );

        $signer = (new HdWalletMnemonicSigner())->signerFor(self::MNEMONIC, 0);

        $this->expectException(\Throwable::class);
        (new OfflineTransferSigner())->sign($tampered, $signer);
    }

    private function transfer(): UnsignedTransfer
    {
        return TronTransactionFixture::unsignedTransfer(
            self::ESCROW,
            self::RECIPIENT,
            self::AMOUNT_SUN,
            (int) (new \DateTimeImmutable('+12 hours'))->format('Uv'),
        );
    }
}
