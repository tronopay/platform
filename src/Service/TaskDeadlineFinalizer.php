<?php

namespace App\Service;

use App\Entity\Task;
use App\Entity\TaskStatus;

/**
 * Auto-completion of an assigned task whose execution deadline has passed:
 * - with a saved result → the task goes to "processed";
 * - without a result    → the task returns to the pool ("prepaid") and all
 *   executor-related fields are cleared.
 *
 * @see ui-spec.md §8
 */
final readonly class TaskDeadlineFinalizer
{
    public function __construct(
        private TaskService $taskService,
    ) {
    }

    public function finalizeExpired(Task $task, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        if (TaskStatus::Assigned !== $task->getStatus()) {
            return false;
        }

        $deadline = $task->getExecutionDeadlineAt();
        if (null === $deadline || $deadline > $now) {
            return false;
        }

        if (null === $task->getResult()) {
            $task->setAssignedTo(null);
            $task->setAssignedAt(null);
            $task->setExecutionDeadlineAt(null);
            $transition = 'to_prepaid_from_assigned';
        } else {
            $transition = 'to_processed';
        }

        try {
            $this->taskService->move($task, $transition);
        } catch (\Throwable) {
            // A concurrent request already finalized (or moved) the task.
            return false;
        }

        return true;
    }
}