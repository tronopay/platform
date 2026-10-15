<?php

namespace App\Mcp\Tool;

use App\Exception\InvalidTaskTransitionException;
use App\Exception\TaskValidationException;
use App\Mcp\Dto\JsonRpcError;
use App\Mcp\Exception\McpException;
use App\Mcp\Serialization\McpNormalizer;
use App\Service\TaskClientApi;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base for task tools: holds the shared dependencies and maps domain
 * exceptions (thrown by {@see TaskClientApi}) onto MCP tool errors.
 */
abstract class AbstractTaskTool implements ToolInterface
{
    public function __construct(
        protected readonly TaskClientApi $clientApi,
        protected readonly McpNormalizer $normalizer,
    ) {
    }

    /**
     * @throws McpException always
     */
    protected function mapException(\Throwable $exception, ?string $taskId = null): McpException
    {
        if ($exception instanceof McpException) {
            return $exception;
        }
        if ($exception instanceof NotFoundHttpException) {
            return new McpException(JsonRpcError::taskNotFound($taskId ?? ''));
        }
        if ($exception instanceof AccessDeniedHttpException) {
            return new McpException(JsonRpcError::invalidPassword());
        }
        if ($exception instanceof InvalidTaskTransitionException) {
            return new McpException(JsonRpcError::invalidTransition($exception->getMessage()));
        }
        if ($exception instanceof TaskValidationException) {
            return new McpException(JsonRpcError::validationFailed($exception->getViolations()));
        }

        return new McpException(JsonRpcError::internal($exception->getMessage()));
    }

    /**
     * Extracts a required string argument.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws McpException when the argument is missing or not a string/number
     */
    protected function requireString(array $arguments, string $key): string
    {
        if (!isset($arguments[$key]) || null === $arguments[$key]) {
            throw new McpException(JsonRpcError::invalidParams(sprintf('Missing required argument "%s".', $key)));
        }
        if (!\is_string($arguments[$key]) && !\is_numeric($arguments[$key])) {
            throw new McpException(JsonRpcError::invalidParams(sprintf('Argument "%s" must be a string.', $key)));
        }

        return (string) $arguments[$key];
    }

    /**
     * Extracts an optional string argument (null when absent/empty).
     *
     * @param array<string, mixed> $arguments
     */
    protected function optionalString(array $arguments, string $key): ?string
    {
        if (!isset($arguments[$key]) || null === $arguments[$key] || '' === $arguments[$key]) {
            return null;
        }

        return (string) $arguments[$key];
    }
}
