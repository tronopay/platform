<?php

declare(strict_types=1);

namespace App\Listener;

use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Message\TaskStatusWebhookMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Event\EnteredEvent;

/**
 * Sends an async webhook callback whenever a task enters "prepaid" or "canceled".
 */
final readonly class TaskStatusWebhookListener
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    #[AsEventListener(event: 'workflow.task_status.entered')]
    public function onEntered(EnteredEvent $event): void
    {
        $task = $event->getSubject();
        if (!$task instanceof Task || $task->getId() === null) {
            return;
        }

        $status = $task->getStatus();
        if ($status !== TaskStatus::Prepaid && $status !== TaskStatus::Canceled) {
            return;
        }

        $webhook = $task->getWebhook();
        if ($webhook === null || $webhook === '') {
            return;
        }

        $this->bus->dispatch(new TaskStatusWebhookMessage($task->getId(), $status->value, $webhook));
    }
}