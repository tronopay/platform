<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Why a payout failed, and whether a future prepare run may retry it.
 *
 * A `retryable` failure is temporary (node hiccup, escrow underfunded, the
 * machine was offline) and the task is picked up again by the next prepare
 * run. A `permanent` failure can never succeed on its own (missing refund
 * address, invalid recipient, wrong key) and must be resolved by an operator
 * before the task is reconsidered.
 */
enum PayoutFailureKind: string
{
    /** Temporary; a new prepare run may retry the task. */
    case Retryable = 'retryable';

    /** Needs operator action; prepare runs must skip the task. */
    case Permanent = 'permanent';

    public function label(): string
    {
        return match ($this) {
            self::Retryable => 'Retryable',
            self::Permanent => 'Permanent',
        };
    }
}