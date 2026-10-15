<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs the payment-window sweep exactly once at a time (shared between the
 * console command and the scheduled Messenger handler).
 */
final readonly class PaymentProcessor
{
    public function __construct(
        private PaymentChecker $paymentChecker,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * @return array{prepaid: int, cancelled: int}|null null when another run holds the lock
     */
    public function process(): ?array
    {
        $lock = $this->lockFactory->createLockFromKey(new Key('app.payment-process'));
        if (!$lock->acquire()) {
            return null;
        }

        try {
            $now = new \DateTimeImmutable();

            return [
                'prepaid' => $this->paymentChecker->processPending($now),
                'cancelled' => $this->paymentChecker->cancelExpired($now),
            ];
        } finally {
            $lock->release();
        }
    }
}
