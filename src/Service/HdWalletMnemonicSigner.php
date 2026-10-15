<?php

declare(strict_types=1);

namespace App\Service;

use IEXBase\TronAPI\Crypto\LocalPrivateKeySigner;
use IEXBase\TronAPI\Crypto\Wallet\DerivationPath;
use IEXBase\TronAPI\Crypto\Wallet\HierarchicalWallet;
use IEXBase\TronAPI\Crypto\Wallet\MnemonicPhrase;

/**
 * Derives per-task escrow signing keys from a BIP-39 mnemonic.
 *
 * Runs only on the air-gapped machine. The derivation path matches the
 * watch-only address derivation used online: the account xpub is
 * `m/44'/195'/0'` and addresses are `0/{index}`, i.e. `m/44'/195'/0'/0/{index}`.
 */
final readonly class HdWalletMnemonicSigner
{
    public function signerFor(string $mnemonic, int $walletIndex, string $passphrase = ''): LocalPrivateKeySigner
    {
        $mnemonic = trim($mnemonic);
        if ($mnemonic === '') {
            throw new \InvalidArgumentException('A mnemonic is required to derive an escrow signing key.');
        }

        $wallet = HierarchicalWallet::fromMnemonic(MnemonicPhrase::parse($mnemonic), $passphrase);

        return $wallet
            ->account(DerivationPath::tronAccount(0, 0, $walletIndex))
            ->signer();
    }
}
