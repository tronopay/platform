<?php

namespace App\Service;

use IEXBase\TronAPI\Crypto\Wallet\ExtendedKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class WalletService
{
    public function __construct(
        #[Autowire(env: 'string:TRON_XPUB_MASTER_KEY')]
        private string $xPub = '',
    )
    {
    }

    public function makeAddress(int $index = 0, bool $change = false): string
    {
        $account = ExtendedKey::fromBase58($this->xPub)->derivePath(sprintf('%d/%d', $change ? 1 : 0, $index));

        return $account->address()->toBase58();
    }
}
