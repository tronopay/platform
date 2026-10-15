<?php

namespace App\Mcp\Resource;

/**
 * Task execution result (password required): task://{id}/result?password=...
 */
final class TaskResultResourceProvider extends AbstractTaskResource
{
    public function templates(): array
    {
        return [[
            'uriTemplate' => 'task://{id}/result?password={password}',
            'name' => 'Task result',
            'description' => 'Worker execution result: text content and attached file metadata (id, name, mime type, size). Requires the task password.',
            'mimeType' => 'application/json',
        ]];
    }

    public function supports(string $uri): bool
    {
        return 1 === preg_match('#^task://(' . self::UUID . ')/result$#', $uri);
    }

    public function read(string $uri, array $params = []): array
    {
        preg_match('#^task://(' . self::UUID . ')/result$#', $uri, $m);
        $taskId = $m[1] ?? '';

        try {
            $result = $this->clientApi->getResult($taskId, $this->password($params));
        } catch (\Throwable $e) {
            throw $this->mapException($e, $taskId);
        }

        return [[
            'uri' => $uri,
            'mimeType' => 'application/json',
            'text' => $this->json($this->normalizer->result($result)),
        ]];
    }
}
