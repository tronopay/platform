<?php

namespace App\Mcp\Dto;

use App\Mcp\Exception\McpException;

/**
 * A parsed JSON-RPC 2.0 request message.
 */
final readonly class JsonRpcRequest
{
    public function __construct(
        public string $method,
        public array $params = [],
        public string|int|null $id = null,
        public bool $notification = false,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws McpException when the payload is not a valid request
     */
    public static function fromArray(array $payload): self
    {
        $method = $payload['method'] ?? null;
        if (!\is_string($method) || '' === $method) {
            throw new McpException(JsonRpcError::invalidRequest('Missing or invalid "method".'));
        }

        $params = $payload['params'] ?? [];
        if (!\is_array($params)) {
            throw new McpException(JsonRpcError::invalidParams('"params" must be an object.'));
        }

        $hasId = \array_key_exists('id', $payload);
        $id = $payload['id'] ?? null;
        if ($hasId && null !== $id && !\is_string($id) && !\is_int($id)) {
            throw new McpException(JsonRpcError::invalidRequest('"id" must be a string, a number or null.'));
        }

        return new self(
            method: $method,
            params: $params,
            id: $hasId ? $id : null,
            notification: !$hasId,
        );
    }
}
