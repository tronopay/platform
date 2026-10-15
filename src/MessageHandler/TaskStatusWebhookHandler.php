<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TaskStatusWebhookMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class TaskStatusWebhookHandler
{
    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    #[AsMessageHandler]
    public function __invoke(TaskStatusWebhookMessage $message): void
    {
        $this->httpClient->request('POST', $message->webhookUrl, [
            'json' => [
                'taskId' => $message->taskId->toRfc4122(),
                'status' => $message->status,
            ],
            'timeout' => 10,
        ]);
    }
}