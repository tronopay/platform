<?php

namespace App\Entity;

/**
 * Where the escrowed money of a finished task goes.
 */
enum PayoutDirection: string
{
    /** The client accepted the result: pay the worker. */
    case Worker = 'worker';

    /** The client rejected the result: refund the client. */
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Worker => 'Payout to worker',
            self::Refund => 'Refund to client',
        };
    }

    /**
     * The direction implied by a terminal task status, or null when the task is
     * not settled yet.
     */
    public static function forStatus(TaskStatus $status): ?self
    {
        return match ($status) {
            TaskStatus::Accepted => self::Worker,
            TaskStatus::Rejected => self::Refund,
            default => null,
        };
    }
}
