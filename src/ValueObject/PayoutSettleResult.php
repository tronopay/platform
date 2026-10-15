<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Outcome of one `app:payout:settle` run (machine holding the keys).
 */
final readonly class PayoutSettleResult
{
    /**
     * @param list<PayoutReportItem> $items
     */
    public function __construct(
        public string $batchId,
        public array $items,
        public ?string $reportPath,
        public bool $dryRun,
    ) {
    }

    public function confirmedCount(): int
    {
        return \count(array_filter($this->items, static fn (PayoutReportItem $i): bool => $i->outcome->isConfirmed()));
    }

    public function failedCount(): int
    {
        return \count(array_filter($this->items, static fn (PayoutReportItem $i): bool => $i->outcome->isFailed()));
    }
}
