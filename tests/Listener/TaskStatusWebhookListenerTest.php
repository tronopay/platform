<?php

declare(strict_types=1);

namespace App\Tests\Listener;

use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Listener\TaskStatusWebhookListener;
use App\Message\TaskStatusWebhookMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

final class TaskStatusWebhookListenerTest extends TestCase
{
    public function testDispatchesWebhookOnPrepaid(): void
    {
        $task = new Task();
        $task->setStatus(TaskStatus::Prepaid);
        $task->setWebhook('https://example.com/hook');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(TaskStatusWebhookMessage::class))
            ->willReturnCallback(fn (object $message): Envelope => new Envelope($message));

        $event = new EnteredEvent($task, new Marking([TaskStatus::Prepaid->value => 1]), new Transition('to_prepaid', 'created', 'prepaid'));
        (new TaskStatusWebhookListener($bus))->onEntered($event);
    }

    public function testDispatchesWebhookOnCanceled(): void
    {
        $task = new Task();
        $task->setStatus(TaskStatus::Canceled);
        $task->setWebhook('https://example.com/hook');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(TaskStatusWebhookMessage::class))
            ->willReturnCallback(fn (object $message): Envelope => new Envelope($message));

        $event = new EnteredEvent($task, new Marking([TaskStatus::Canceled->value => 1]), new Transition('to_canceled_created', 'created', 'canceled'));
        (new TaskStatusWebhookListener($bus))->onEntered($event);
    }

    public function testSkipsWhenNoWebhookConfigured(): void
    {
        $task = new Task();
        $task->setStatus(TaskStatus::Prepaid);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $event = new EnteredEvent($task, new Marking([TaskStatus::Prepaid->value => 1]), new Transition('to_prepaid', 'created', 'prepaid'));
        (new TaskStatusWebhookListener($bus))->onEntered($event);
    }

    public function testSkipsForUnrelatedStatus(): void
    {
        $task = new Task();
        $task->setWebhook('https://example.com/hook');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $event = new EnteredEvent($task, new Marking([TaskStatus::Created->value => 1]), new Transition('to_created', 'created', 'created'));
        (new TaskStatusWebhookListener($bus))->onEntered($event);
    }
}