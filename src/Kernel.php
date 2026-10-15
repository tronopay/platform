<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        // "offline" is the settlement environment (docs/payouts.md): it runs on
        // the machine holding the escrow mnemonic, has no database, and talks to
        // the TRON node directly so app:payout:settle can build, sign and
        // broadcast payouts.
        return ['prod', 'dev', 'test', 'offline'];
    }
}
