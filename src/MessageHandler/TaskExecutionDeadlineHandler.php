<?php

namespace App\MessageHandler;

use App\Entity\Task;
use App\Message\TaskExecutionDeadlineMessage;
use App\Repository\TaskRepository;
use App\Service\TaskDeadlineFinalizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TaskExecutionDeadlineHandler
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TaskDeadlineFinalizer $deadlineFinalizer,
    ) {
    }

    public function __invoke(TaskExecutionDeadlineMessage $message): void
    {
        $task = $this->tasks->find($message->taskId);
        if (!$task instanceof Task) {
            return; // Task no longer exists — nothing to finalize.
        }

        $this->deadlineFinalizer->finalizeExpired($task);
    }
}