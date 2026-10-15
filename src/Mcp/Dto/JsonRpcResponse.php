<?php

namespace App\Mcp\Dto;

/**
 * A JSON-RPC 2.0 response message (either a result or an error).
 */
final readonly class JsonRpcResponse implements \JsonSerializable
{
    public const string VERSION = '2.0';

    private function __construct(
        public string|int|null $id,
        public mixed $result = null,
        public ?JsonRpcError $error = null,
    ) {
    }

    public static function result(string|int|null $id, mixed $result): self
    {
        return new self(id: $id, result: $result);
    }

    public static function error(string|int|null $id, JsonRpcError $error): self
    {
        return new self(id: $id, error: $error);
    }

    public function isError(): bool
    {
        return null !== $this->error;
    }

    /**
     * @return array{jsonrpc: string, id: string|int|null, result?: mixed, error?: mixed}
     */
    public function jsonSerialize(): array
    {
        $response = [
            'jsonrpc' => self::VERSION,
            'id' => $this->id,
        ];

        if ($this->isError()) {
            $response['error'] = $this->error;
        } else {
            $response['result'] = $this->result;
        }

        return $response;
    }

    /**
     * Encodes into a JsonResponse for the HTTP transport.
     */
    public function toJson(): string
    {
        $json = json_encode($this, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            throw new \RuntimeException('Failed to encode JSON-RPC response.');
        }

        return $json;
    }
}
