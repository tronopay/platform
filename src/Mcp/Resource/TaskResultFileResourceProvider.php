<?php

namespace App\Mcp\Resource;

use App\Mcp\Serialization\McpNormalizer;
use App\Service\TaskClientApi;
use App\Storage\FileStorageInterface;

/**
 * A single result file, returned inline as base64 (password required):
 * task://{id}/result/files/{fileId}?password=...
 */
final class TaskResultFileResourceProvider extends AbstractTaskResource
{
    public function __construct(
        TaskClientApi $clientApi,
        McpNormalizer $normalizer,
        private readonly FileStorageInterface $storage,
    ) {
        parent::__construct($clientApi, $normalizer);
    }

    public function templates(): array
    {
        return [[
            'uriTemplate' => 'task://{id}/result/files/{fileId}?password={password}',
            'name' => 'Task result file',
            'description' => 'A single file attached to a task result, returned inline as base64. Requires the task password.',
        ]];
    }

    public function supports(string $uri): bool
    {
        return 1 === preg_match('#^task://(' . self::UUID . ')/result/files/(' . self::UUID . ')$#', $uri);
    }

    public function read(string $uri, array $params = []): array
    {
        preg_match('#^task://(' . self::UUID . ')/result/files/(' . self::UUID . ')$#', $uri, $m);
        $taskId = $m[1] ?? '';
        $fileId = $m[2] ?? '';

        try {
            $file = $this->clientApi->getResultFile($taskId, $fileId, $this->password($params));
        } catch (\Throwable $e) {
            throw $this->mapException($e, $taskId);
        }

        $contents = $this->storage->get((string) $file->getVirtualPath());

        return [[
            'uri' => $uri,
            'mimeType' => (string) $file->getMimeType(),
            'blob' => base64_encode($contents),
        ]];
    }
}
