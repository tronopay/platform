<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What the settlement machine did with one intent item, as reported back.
 *
 * `confirmed` means the network executed the transfer successfully and the
 * machine verified the executed contract matches the intent, so the money is
 * guaranteed to have moved. `broadcast` means the node accepted the signed
 * transaction but no receipt proving execution was available yet. `failed`
 * means nothing was sent (or the send was refused) and carries a
 * {@see PayoutFailureKind}.
 */
enum PayoutOutcome: string
{
    case Confirmed = 'confirmed';
    case Broadcast = 'broadcast';
    case Failed = 'failed';

    /** The status a payout row takes when this outcome is applied. */
    public function toStatus(): PayoutStatus
    {
        return match ($this) {
            self::Confirmed => PayoutStatus::Confirmed,
            self::Broadcast => PayoutStatus::Broadcast,
            self::Failed => PayoutStatus::Failed,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmed',
            self::Broadcast => 'Broadcast',
            self::Failed => 'Failed',
        };
    }

    public function isConfirmed(): bool
    {
        return self::Confirmed === $this;
    }

    public function isBroadcast(): bool
    {
        return self::Broadcast === $this;
    }

    public function isFailed(): bool
    {
        return self::Failed === $this;
    }

    /**
     * Whether the money movement is finished and must never be repeated.
     */
    public function isSettled(): bool
    {
        return self::Broadcast === $this || self::Confirmed === $this;
    }
}
