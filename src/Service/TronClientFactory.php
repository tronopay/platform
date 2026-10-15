<?php

declare(strict_types=1);

namespace App\Service;

use IEXBase\TronAPI\Configuration\NodeConfiguration;
use IEXBase\TronAPI\Enum\Network;
use IEXBase\TronAPI\Tron;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds a {@see Tron} client from the configured network and optional TronGrid key.
 */
final readonly class TronClientFactory
{
    public function __construct(
        #[Autowire('%env(TRON_NETWORK)%')]
        private string $network = 'mainnet',
        #[Autowire('%env(TRON_API_KEY)%')]
        private string $apiKey = '',
    ) {
    }

    public function create(): Tron
    {
        $network = Network::from($this->network);
        $key = trim($this->apiKey) !== '' ? trim($this->apiKey) : null;

        return Tron::create(NodeConfiguration::forNetwork($network, $key));
    }
}
