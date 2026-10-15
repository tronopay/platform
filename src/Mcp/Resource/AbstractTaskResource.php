<?php

namespace App\Mcp\Resource;

use App\Mcp\Dto\JsonRpcError;
use App\Mcp\Exception\McpException;
use App\Mcp\Serialization\McpNormalizer;
use App\Service\TaskClientApi;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base for the task resource providers: shared dependencies, URI matching and
 * the mapping of domain exceptions onto MCP errors.
 */
abstract class AbstractTaskResource implements ResourceInterface
{
    /**
     * Matches a canonical lowercase UUID.
     */
    protected const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        protected readonly TaskClientApi $clientApi,
        protected readonly McpNormalizer $normalizer,
    ) {
    }

    /**
     * These resources address runtime entities, so there is no static list.
     *
     * @return list<array{uri: string, name: string, description?: string, mimeType?: string}>
     */
    public function list(): array
    {
        return [];
    }

    /**
     * @throws McpException
     */
    protected function mapException(\Throwable $exception, string $taskId = ''): McpException
    {
        if ($exception instanceof McpException) {
            return $exception;
        }
        if ($exception instanceof NotFoundHttpException) {
            return new McpException(JsonRpcError::taskNotFound($taskId));
        }
        if ($exception instanceof AccessDeniedHttpException) {
            return new McpException(JsonRpcError::invalidPassword());
        }

        return new McpException(JsonRpcError::internal($exception->getMessage()));
    }

    /**
     * Extracts the "password" query parameter of a resource URI.
     *
     * @param array<string, mixed> $params
     */
    protected function password(array $params): string
    {
        $password = $params['password'] ?? '';

        return \is_string($password) ? $password : (string) $password;
    }

    protected function json(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $json ? '' : $json;
    }
}
