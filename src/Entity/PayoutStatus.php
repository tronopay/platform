<?php

namespace App\Entity;

/**
 * Lifecycle of one escrow payout instruction.
 *
 * Prepared online by the preparer, settled on the machine that holds the
 * mnemonic (which reports the outcome back), then folded into `broadcast` /
 * `confirmed` / `failed`. Anything that needs a human is `failed`, or
 * `abandoned` by an operator.
 */
enum PayoutStatus: string
{
    /** The intent file was written; waiting for the settlement machine to settle it. */
    case Prepared = 'prepared';

    /** The machine reported the signed transaction was accepted by the network. */
    case Broadcast = 'broadcast';

    /** The machine reported the transaction executed (receipt SUCCESS) with a matching transfer. */
    case Confirmed = 'confirmed';

    /** Could not be prepared or broadcast; needs manual resolution. */
    case Failed = 'failed';

    /** Closed by an operator; the task may be prepared again. */
    case Abandoned = 'abandoned';

    /**
     * Whether the money movement is finished and must never be repeated.
     */
    public function isSettled(): bool
    {
        return self::Broadcast === $this || self::Confirmed === $this;
    }

    /**
     * Whether a new prepare run may rebuild this instruction.
     *
     * Only a retryable `failed` or an explicitly `abandoned` payout is picked up
     * again; a `prepared` payout is left alone while the settlement machine may
     * still process it, and must be closed by an operator first.
     */
    public function isRebuildable(): bool
    {
        return self::Failed === $this || self::Abandoned === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Prepared => 'Awaiting settlement',
            self::Broadcast => 'Broadcast',
            self::Confirmed => 'Confirmed',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
        };
    }
}
