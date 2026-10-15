<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * A node-built native TRX transfer that is not signed yet.
 *
 * It carries everything the air-gapped machine needs to re-verify and sign the
 * transfer: the exact protobuf bytes (`rawDataHex`), their hash (`txId`), and
 * the structured body for integrity checks.
 */
final readonly class UnsignedTransfer
{
    /**
     * @param array<string, mixed> $rawData structured transaction raw_data
     */
    public function __construct(
        public string $txId,
        public string $fromAddress,
        public string $toAddress,
        public int $amountSun,
        public int $expirationMilliseconds,
        public array $rawData,
        public string $rawDataHex,
    ) {
    }
}
