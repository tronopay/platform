<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Outcome of one `app:payout:prepare` run (online server).
 */
final readonly class PayoutPrepareResult
{
    /**
     * @param list<array{taskId: string, direction: string, amountSun: int, toAddress: string}> $prepared
     * @param list<array{taskId: string, error: string}>                                         $failed
     * @param list<array{taskId: string, reason: string}>                                        $skipped
     */
    public function __construct(
        public string $batchId,
        public array $prepared,
        public array $failed,
        public array $skipped,
        public ?string $intentPath,
        public bool $dryRun,
    ) {
    }

    public function preparedTotalSun(): int
    {
        return array_sum(array_column($this->prepared, 'amountSun'));
    }

    public function isEmpty(): bool
    {
        return $this->prepared === [] && $this->failed === [];
    }
}
