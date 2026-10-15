<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Task;
use App\Entity\TaskResult;
use App\Entity\TaskStatus;
use App\Service\TaskDeadlineFinalizer;
use App\Service\TaskService;
use PHPUnit\Framework\TestCase;

/**
 * @see ui-spec.md §8
 */
final class TaskDeadlineFinalizerTest extends TestCase
{
    public function testDoesNothingWhenTaskIsNotAssigned(): void
    {
        $task = (new Task())->setStatus(TaskStatus::Prepaid);

        $finalizer = new TaskDeadlineFinalizer($this->createStub(TaskService::class));

        self::assertFalse($finalizer->finalizeExpired($task, new \DateTimeImmutable()));
    }

    public function testDoesNothingWhenDeadlineIsInTheFuture(): void
    {
        $task = (new Task())->setStatus(TaskStatus::Assigned);
        $task->setExecutionDeadlineAt((new \DateTimeImmutable())->modify('+1 hour'));

        $finalizer = new TaskDeadlineFinalizer($this->createStub(TaskService::class));

        self::assertFalse($finalizer->finalizeExpired($task, new \DateTimeImmutable()));
    }

    public function testClearsExecutorAndReturnsTaskToPoolWhenNoResult(): void
    {
        $task = (new Task())->setStatus(TaskStatus::Assigned);
        $task->setExecutionDeadlineAt((new \DateTimeImmutable())->modify('-1 minute'));

        $taskService = $this->createMock(TaskService::class);
        $taskService->expects($this->once())
            ->method('move')
            ->with($this->identicalTo($task), 'to_prepaid_from_assigned');

        $finalizer = new TaskDeadlineFinalizer($taskService);

        $now = new \DateTimeImmutable();
        self::assertTrue($finalizer->finalizeExpired($task, $now));
        self::assertNull($task->getAssignedTo());
        self::assertNull($task->getAssignedAt());
        self::assertNull($task->getExecutionDeadlineAt());
    }

    public function testMarksProcessedWhenResultExists(): void
    {
        $task = (new Task())->setStatus(TaskStatus::Assigned);
        $task->setExecutionDeadlineAt((new \DateTimeImmutable())->modify('-1 minute'));
        $task->setResult(new TaskResult());

        $taskService = $this->createMock(TaskService::class);
        $taskService->expects($this->once())
            ->method('move')
            ->with($this->identicalTo($task), 'to_processed');

        $finalizer = new TaskDeadlineFinalizer($taskService);

        self::assertTrue($finalizer->finalizeExpired($task, new \DateTimeImmutable()));
    }

    public function testSwallowsWorkflowFailureOnConcurrentFinalization(): void
    {
        $task = (new Task())->setStatus(TaskStatus::Assigned);
        $task->setExecutionDeadlineAt((new \DateTimeImmutable())->modify('-1 minute'));

        $taskService = $this->createStub(TaskService::class);
        $taskService->method('move')->willThrowException(new \Exception('transition unavailable'));

        $finalizer = new TaskDeadlineFinalizer($taskService);

        self::assertFalse($finalizer->finalizeExpired($task, new \DateTimeImmutable()));
    }
}