<?php

namespace App\Mcp\Dto;

/**
 * JSON-RPC 2.0 error object with MCP application-specific codes.
 */
final readonly class JsonRpcError implements \JsonSerializable
{
    // JSON-RPC standard codes
    public const int PARSE_ERROR = -32700;
    public const int INVALID_REQUEST = -32600;
    public const int METHOD_NOT_FOUND = -32601;
    public const int INVALID_PARAMS = -32602;
    public const int INTERNAL_ERROR = -32603;

    // Application-specific codes (see docs/mcp-server-design.md §7)
    public const int TASK_NOT_FOUND = -32000;
    public const int INVALID_PASSWORD = -32001;
    public const int INVALID_TRANSITION = -32002;
    public const int VALIDATION_FAILED = -32003;

    public function __construct(
        public int $code,
        public string $message,
        public mixed $data = null,
    ) {
    }

    public static function parseError(string $message = 'Parse error.'): self
    {
        return new self(self::PARSE_ERROR, $message);
    }

    public static function invalidRequest(string $message = 'Invalid request.'): self
    {
        return new self(self::INVALID_REQUEST, $message);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(self::METHOD_NOT_FOUND, sprintf('Method "%s" not found.', $method));
    }

    public static function invalidParams(string $message = 'Invalid params.'): self
    {
        return new self(self::INVALID_PARAMS, $message);
    }

    public static function internal(string $message = 'Internal error.'): self
    {
        return new self(self::INTERNAL_ERROR, $message);
    }

    public static function taskNotFound(string $taskId): self
    {
        return new self(self::TASK_NOT_FOUND, 'Task not found.', ['taskId' => $taskId]);
    }

    public static function invalidPassword(): self
    {
        return new self(self::INVALID_PASSWORD, 'The task password is missing or invalid.');
    }

    public static function invalidTransition(string $message): self
    {
        return new self(self::INVALID_TRANSITION, $message);
    }

    /**
     * @param list<string> $violations
     */
    public static function validationFailed(array $violations): self
    {
        return new self(self::VALIDATION_FAILED, 'Input validation failed.', ['violations' => $violations]);
    }

    /**
     * @return array{code: int, message: string, data?: mixed}
     */
    public function jsonSerialize(): array
    {
        $error = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if (null !== $this->data) {
            $error['data'] = $this->data;
        }

        return $error;
    }
}
