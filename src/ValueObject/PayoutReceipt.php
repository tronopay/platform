<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Normalized execution info of one broadcast transaction, as seen by the node.
 *
 * Returned by the settlement gateway after a broadcast so the machine can turn
 * a `broadcast` into a `confirmed` outcome with the real fee and block.
 */
final readonly class PayoutReceipt
{
    public function __construct(
        public string $txId,
        public bool $success,
        public int $blockNumber,
        public int $feeSun,
        public ?string $result = null,
        public ?string $message = null,
    ) {
    }
}