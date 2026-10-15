<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

final readonly class TaskStatusWebhookMessage
{
    public function __construct(
        public Uuid $taskId,
        public string $status,
        public string $webhookUrl,
    ) {
    }
}