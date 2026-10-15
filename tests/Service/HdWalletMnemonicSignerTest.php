<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HdWalletMnemonicSigner;
use App\Service\WalletService;
use IEXBase\TronAPI\Crypto\Wallet\HierarchicalWallet;
use IEXBase\TronAPI\Crypto\Wallet\MnemonicPhrase;
use PHPUnit\Framework\TestCase;

/**
 * The offline signer must derive exactly the addresses the online watch-only
 * wallet derives from the account xpub, otherwise a correctly prepared bundle
 * would be refused (or, worse, signed with the wrong key).
 */
final class HdWalletMnemonicSignerTest extends TestCase
{
    private const MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';

    public function testDerivesTheDocumentedAddresses(): void
    {
        $signer = new HdWalletMnemonicSigner();

        self::assertSame(
            'TUEZSdKsoDHQMeZwihtdoBiN46zxhGWYdH',
            $signer->signerFor(self::MNEMONIC, 0)->address()->toBase58(),
        );
        self::assertSame(
            'TFj86XAQYBPvwUknt9yxmbc4k3zwUC7iVW',
            $signer->signerFor(self::MNEMONIC, 7)->address()->toBase58(),
        );
    }

    public function testMatchesTheWatchOnlyWalletDerivation(): void
    {
        $signer = new HdWalletMnemonicSigner();

        // The same address the online side computes from TRON_XPUB_MASTER_KEY.
        $watchOnly = new WalletService($this->xpubFor(self::MNEMONIC));

        foreach ([0, 1, 5, 42] as $index) {
            self::assertSame(
                $watchOnly->makeAddress($index),
                $signer->signerFor(self::MNEMONIC, $index)->address()->toBase58(),
                sprintf('Index %d must match the watch-only derivation.', $index),
            );
        }
    }

    public function testAPassphraseChangesTheDerivedKey(): void
    {
        $signer = new HdWalletMnemonicSigner();

        self::assertSame(
            'TAyDUYP5rcf56xFwrg8cU1qQwvnWpkeapM',
            $signer->signerFor(self::MNEMONIC, 0, 'TREZOR')->address()->toBase58(),
        );
        self::assertNotSame(
            $signer->signerFor(self::MNEMONIC, 0)->address()->toBase58(),
            $signer->signerFor(self::MNEMONIC, 0, 'TREZOR')->address()->toBase58(),
        );
    }

    public function testAnEmptyMnemonicIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HdWalletMnemonicSigner())->signerFor('   ', 0);
    }

    private function xpubFor(string $mnemonic): string
    {
        return HierarchicalWallet::fromMnemonic(MnemonicPhrase::parse($mnemonic))->accountExtendedPublicKey(0);
    }
}
