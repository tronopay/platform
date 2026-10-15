<?php

namespace App\Mcp\Serialization;

use App\Dto\CreateTaskResponse;
use App\Dto\GetTaskResultResponse;
use App\Dto\TaskResultFileResponse;
use App\Entity\Task;
use App\ValueObject\Price;

/**
 * Converts entities and DTOs into clean, stable JSON for MCP tool results and
 * resources. Field names mirror the REST API (camelCase), without the JSON-LD
 * and Hydra envelopes API Platform adds.
 */
final readonly class McpNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function task(Task $task): array
    {
        return [
            'id' => (string) $task->getId(),
            'statement' => (string) $task->getStatement(),
            'status' => $task->getStatus()?->value,
            'type' => $task->getType()?->value,
            'priceSun' => $task->getPriceSun(),
            'priceTrx' => Price::toPrice($task->getPriceSun()),
            'location' => $task->getLocation(),
            'refundAddress' => $task->getRefundAddress(),
            'webhook' => $task->getWebhook(),
            'createdAt' => $task->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $task->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'paymentDeadlineAt' => $task->getPaymentDeadlineAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param Task[] $tasks
     *
     * @return list<array<string, mixed>>
     */
    public function tasks(array $tasks): array
    {
        return array_map(fn (Task $task): array => $this->task($task), $tasks);
    }

    /**
     * @return array<string, mixed>
     */
    public function created(CreateTaskResponse $response): array
    {
        return [
            'id' => (string) $response->getId(),
            'status' => $response->getTaskStatus()?->value,
            'price' => $response->getPrice(),
            'paymentAddress' => $response->getPaymentAddress(),
            'hint' => $response->getHint(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function result(GetTaskResultResponse $response): array
    {
        return [
            'id' => null !== $response->id ? (string) $response->id : null,
            'status' => $response->taskStatus?->value,
            'result' => $response->result,
            'files' => array_map(fn (TaskResultFileResponse $file): array => $this->resultFile($file), $response->files),
            'hint' => $response->hint,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resultFile(TaskResultFileResponse $file): array
    {
        return [
            'id' => null !== $file->id ? (string) $file->id : null,
            'name' => $file->name,
            'mimeType' => $file->mimeType,
            'size' => $file->size,
            'url' => $file->url,
        ];
    }
}
