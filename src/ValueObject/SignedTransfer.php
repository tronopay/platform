<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Result of signing one {@see UnsignedTransfer} on the air-gapped machine.
 */
final readonly class SignedTransfer
{
    /**
     * @param array<string, mixed> $payload native signed transaction object
     */
    public function __construct(
        public array $payload,
        public string $txId,
        public string $signature,
        public string $signerAddress,
    ) {
    }
}
