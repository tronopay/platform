<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Outcome of one `app:payout:apply-report` run (online server).
 */
final readonly class PayoutReportApplyResult
{
    /**
     * @param list<array{taskId: string, payoutId: string, status: string}> $applied
     * @param list<array{taskId: string, payoutId: string, reason: string}> $skipped
     * @param list<array{taskId: string, payoutId: string, error: string}> $failed
     */
    public function __construct(
        public string $batchId,
        public array $applied,
        public array $skipped,
        public array $failed,
        public bool $dryRun,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->applied === [] && $this->failed === [];
    }
}
