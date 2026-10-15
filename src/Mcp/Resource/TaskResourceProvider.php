<?php

namespace App\Mcp\Resource;

/**
 * Public task metadata (no password required): task://{id}.
 */
final class TaskResourceProvider extends AbstractTaskResource
{
    public function templates(): array
    {
        return [[
            'uriTemplate' => 'task://{id}',
            'name' => 'Task',
            'description' => 'Public task metadata: statement, status, type, price, location, refund address, webhook and timestamps.',
            'mimeType' => 'application/json',
        ]];
    }

    public function supports(string $uri): bool
    {
        return 1 === preg_match('#^task://(' . self::UUID . ')$#', $uri);
    }

    public function read(string $uri, array $params = []): array
    {
        preg_match('#^task://(' . self::UUID . ')$#', $uri, $m);
        $taskId = $m[1] ?? '';

        try {
            $task = $this->clientApi->getTask($taskId);
        } catch (\Throwable $e) {
            throw $this->mapException($e, $taskId);
        }

        return [[
            'uri' => $uri,
            'mimeType' => 'application/json',
            'text' => $this->json($this->normalizer->task($task)),
        ]];
    }
}
