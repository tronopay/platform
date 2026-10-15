<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Payout;
use App\Entity\PayoutDirection;
use App\Entity\PayoutFailureKind;
use App\Entity\PayoutStatus;
use App\Entity\Task;
use App\Entity\TaskStatus;
use PHPUnit\Framework\TestCase;

final class PayoutTest extends TestCase
{
    public function testAFreshPayoutIsPrepared(): void
    {
        $payout = new Payout(task: new Task());

        self::assertSame(PayoutStatus::Prepared, $payout->getStatus());
        self::assertNotNull($payout->getId());
        self::assertNull($payout->getFailureKind());
    }

    public function testAPreparedPayoutMayNotBeRebuiltWithoutOperatorAction(): void
    {
        $payout = new Payout(task: new Task());
        $payout->setStatus(PayoutStatus::Prepared);

        self::assertFalse($payout->canBeRebuilt(new \DateTimeImmutable()));
    }

    public function testARetryableFailedPayoutIsRebuildableButAPermanentOneIsNot(): void
    {
        $now = new \DateTimeImmutable();

        $retryable = new Payout(task: new Task());
        $retryable->setStatus(PayoutStatus::Failed)->setFailureKind(PayoutFailureKind::Retryable);
        self::assertTrue($retryable->canBeRebuilt($now));

        $permanent = new Payout(task: new Task());
        $permanent->setStatus(PayoutStatus::Failed)->setFailureKind(PayoutFailureKind::Permanent);
        self::assertFalse($permanent->canBeRebuilt($now));
    }

    public function testAnAbandonedPayoutIsRebuildable(): void
    {
        $payout = new Payout(task: new Task());
        $payout->setStatus(PayoutStatus::Abandoned);

        self::assertTrue($payout->canBeRebuilt(new \DateTimeImmutable()));
    }

    public function testBroadcastAndConfirmedPayoutsAreSettledAndNeverRebuilt(): void
    {
        $now = new \DateTimeImmutable();

        $broadcast = new Payout(task: new Task());
        $broadcast->setStatus(PayoutStatus::Broadcast);
        self::assertTrue($broadcast->getStatus()->isSettled());
        self::assertFalse($broadcast->canBeRebuilt($now));

        $confirmed = new Payout(task: new Task());
        $confirmed->setStatus(PayoutStatus::Confirmed);
        self::assertTrue($confirmed->getStatus()->isSettled());
        self::assertFalse($confirmed->canBeRebuilt($now));
    }

    public function testDirectionFollowsTheTerminalTaskStatus(): void
    {
        self::assertSame(PayoutDirection::Worker, PayoutDirection::forStatus(TaskStatus::Accepted));
        self::assertSame(PayoutDirection::Refund, PayoutDirection::forStatus(TaskStatus::Rejected));
        self::assertNull(PayoutDirection::forStatus(TaskStatus::Processed));
        self::assertNull(PayoutDirection::forStatus(TaskStatus::Finished));
    }
}
