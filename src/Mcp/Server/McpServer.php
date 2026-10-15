<?php

namespace App\Mcp\Server;

use App\Mcp\Dto\JsonRpcError;
use App\Mcp\Dto\JsonRpcRequest;
use App\Mcp\Dto\JsonRpcResponse;
use App\Mcp\Exception\McpException;
use App\Mcp\Prompt\PromptRegistry;
use App\Mcp\Resource\ResourceRegistry;
use App\Mcp\Tool\ToolRegistry;

/**
 * Dispatches JSON-RPC requests to the MCP handlers. Transport-agnostic: an
 * HTTP transport feeds it parsed requests; it returns response messages.
 */
final readonly class McpServer
{
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private InitializeHandler $initializeHandler,
        private ToolRegistry $tools,
        private ResourceRegistry $resources,
        private PromptRegistry $prompts,
    ) {
    }

    /**
     * Dispatches one request. Returns null for notifications (no response).
     */
    public function dispatch(JsonRpcRequest $request): ?JsonRpcResponse
    {
        if ($request->notification) {
            $this->handleNotification($request->method, $request->params);

            return null;
        }

        try {
            return JsonRpcResponse::result($request->id, $this->handle($request->method, $request->params));
        } catch (McpException $exception) {
            return JsonRpcResponse::error($request->id, $exception->error());
        } catch (\OutOfRangeException $exception) {
            return JsonRpcResponse::error($request->id, JsonRpcError::invalidParams($exception->getMessage()));
        } catch (\InvalidArgumentException $exception) {
            return JsonRpcResponse::error($request->id, JsonRpcError::invalidParams($exception->getMessage()));
        } catch (\Throwable $exception) {
            return JsonRpcResponse::error($request->id, JsonRpcError::internal($exception->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handle(string $method, array $params): mixed
    {
        return match ($method) {
            'initialize' => $this->initializeHandler->initialize($params),
            'ping' => (object) [],
            'tools/list' => ['tools' => $this->tools->list()],
            'tools/call' => $this->callTool($params),
            'resources/list' => ['resources' => $this->resources->list()],
            'resources/templates/list' => ['resourceTemplates' => $this->resources->templates()],
            'resources/read' => $this->readResource($params),
            'prompts/list' => ['prompts' => $this->prompts->list()],
            'prompts/get' => $this->getPrompt($params),
            default => throw new McpException(JsonRpcError::methodNotFound($method)),
        };
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || '' === $name || !$this->tools->has($name)) {
            throw new McpException(JsonRpcError::invalidParams('A known tool "name" is required.'));
        }

        $arguments = $params['arguments'] ?? [];
        if (!\is_array($arguments)) {
            throw new McpException(JsonRpcError::invalidParams('"arguments" must be an object.'));
        }

        try {
            $payload = $this->tools->get($name)->call($arguments);
        } catch (McpException $exception) {
            // Domain errors are reported in the tool result (isError: true) so
            // the LLM can see and react to them, per MCP convention.
            return self::toolError($exception->error());
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => self::encode($payload),
            ]],
            'isError' => false,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function readResource(array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (!\is_string($uri) || '' === $uri) {
            throw new McpException(JsonRpcError::invalidParams('A string "uri" is required.'));
        }

        [$baseUri, $query] = self::splitUri($uri);

        return ['contents' => $this->resources->read($baseUri, $query)];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function getPrompt(array $params): array
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || '' === $name || !$this->prompts->has($name)) {
            throw new McpException(JsonRpcError::invalidParams('A known prompt "name" is required.'));
        }

        $arguments = $params['arguments'] ?? [];
        if (!\is_array($arguments)) {
            throw new McpException(JsonRpcError::invalidParams('"arguments" must be an object.'));
        }

        return $this->prompts->get($name)->get($arguments);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleNotification(string $method, array $params): void
    {
        // "notifications/initialized" and "notifications/cancelled" need no action.
    }

    /**
     * @return array<string, mixed>
     */
    private static function toolError(JsonRpcError $error): array
    {
        $data = [
            'code' => $error->code,
            'message' => $error->message,
        ];
        if (null !== $error->data) {
            $data['data'] = $error->data;
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => self::encode(['error' => $data]),
            ]],
            'isError' => true,
        ];
    }

    private static function encode(mixed $value): string
    {
        $json = json_encode($value, self::JSON_FLAGS | JSON_PRETTY_PRINT);
        if (false === $json) {
            throw new \RuntimeException('Failed to encode tool result as JSON.');
        }

        return $json;
    }

    /**
     * Splits a resource URI into its base URI and query parameters.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function splitUri(string $uri): array
    {
        $query = [];
        $pos = strpos($uri, '?');
        if (false !== $pos) {
            $base = substr($uri, 0, $pos);
            parse_str(substr($uri, $pos + 1), $query);
        } else {
            $base = $uri;
        }

        return [$base, $query];
    }
}
