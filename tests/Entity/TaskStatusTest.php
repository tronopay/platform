<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\TaskStatus;
use PHPUnit\Framework\TestCase;

/**
 * The history / earnings split lives in the enum, so it is worth pinning down:
 * these two predicates decide what the worker sees and what is summed as earned.
 *
 * @see ui-spec.md §18
 */
final class TaskStatusTest extends TestCase
{
    public function testHistoryStatusesAreThoseTheWorkerAlreadySubmitted(): void
    {
        self::assertFalse(TaskStatus::Created->isHistory());
        self::assertFalse(TaskStatus::Canceled->isHistory());
        self::assertFalse(TaskStatus::Prepaid->isHistory(), 'A task back in the pool has no executor and no history.');
        self::assertFalse(TaskStatus::Assigned->isHistory(), 'An active task is not history yet.');

        self::assertTrue(TaskStatus::Processed->isHistory());
        self::assertTrue(TaskStatus::Accepted->isHistory());
        self::assertTrue(TaskStatus::Rejected->isHistory());
        self::assertTrue(TaskStatus::Finished->isHistory());
    }

    public function testOnlyAcceptedCountsAsPaid(): void
    {
        self::assertTrue(TaskStatus::Accepted->isPaid());

        // "finished" is reachable from both accepted and rejected, so it cannot
        // prove a payout and must not count towards the earned sum.
        self::assertFalse(TaskStatus::Finished->isPaid());
        self::assertFalse(TaskStatus::Processed->isPaid());
        self::assertFalse(TaskStatus::Rejected->isPaid());
    }

    public function testHistoryValuesMatchTheHistoryPredicate(): void
    {
        self::assertSame(['processed', 'accepted', 'rejected', 'finished'], TaskStatus::historyValues());
    }

    public function testEveryStatusHasAHumanReadableLabel(): void
    {
        foreach (TaskStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
            self::assertSame(ucfirst($status->label()), $status->label());
        }

        // The internal workflow wording is replaced by the executor's language.
        self::assertSame('Under review', TaskStatus::Processed->label());
        self::assertSame('In progress', TaskStatus::Assigned->label());
    }
}
