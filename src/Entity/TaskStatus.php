<?php

namespace App\Entity;

enum TaskStatus: string
{
    case Created = 'created';
    case Canceled = 'canceled';
    case Prepaid = 'prepaid';
    case Assigned = 'assigned';
    case Processed = 'processed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    case Finished = 'finished';

    public static function forStatusSelect(): array
    {
        return array_combine(
            array_map(fn($enum) => $enum->name, self::cases()),
            array_map(fn($enum) => $enum->value, self::cases()),
        );
    }

    /**
     * The worker has already submitted work for the task, so it left the
     * worker's hands and belongs in the worker's history (ui-spec.md §18).
     *
     * Deliberately excludes "prepaid": a task returned to the pool (release or
     * an expired deadline without a result) is cleared of its executor and is
     * therefore not part of anybody's history.
     */
    public function isHistory(): bool
    {
        return match ($this) {
            self::Processed, self::Accepted, self::Rejected, self::Finished => true,
            default => false,
        };
    }

    /**
     * The client accepted the result and the money was released to the worker.
     *
     * Note: "finished" is intentionally not treated as paid. It is reachable
     * from both "accepted" and "rejected" (see docs/proposals.md §1.3), so the
     * status alone cannot prove that a payout happened.
     */
    public function isPaid(): bool
    {
        return self::Accepted === $this;
    }

    /**
     * Human-readable label for the executor-facing UI. The raw workflow value
     * ("processed", "assigned") is internal terminology.
     */
    public function label(): string
    {
        return match ($this) {
            self::Created => 'Awaiting payment',
            self::Canceled => 'Canceled',
            self::Prepaid => 'Available',
            self::Assigned => 'In progress',
            self::Processed => 'Under review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Finished => 'Finished',
        };
    }

    /**
     * Database values of the statuses that make up a worker's history.
     *
     * @return list<string>
     */
    public static function historyValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isHistory()),
        ));
    }
}
