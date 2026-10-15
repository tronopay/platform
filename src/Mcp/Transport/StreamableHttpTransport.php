<?php

namespace App\Mcp\Transport;

use App\Mcp\Dto\JsonRpcError;
use App\Mcp\Dto\JsonRpcRequest;
use App\Mcp\Dto\JsonRpcResponse;
use App\Mcp\Exception\McpException;
use App\Mcp\Server\McpServer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP Streamable HTTP transport.
 *
 * A single endpoint accepts POST JSON-RPC requests and returns JSON (or an
 * empty 202 for notifications). Server-initiated SSE is not needed because the
 * server is request-driven; the response content type is chosen by the MCP
 * client's Accept header, but JSON is always valid for request/response flows.
 */
final readonly class StreamableHttpTransport implements TransportInterface
{
    public function __construct(
        private McpServer $server,
    ) {
    }

    public function handle(Request $request): Response
    {
        if (Request::METHOD_DELETE === $request->getMethod()) {
            // Session teardown — the server is stateless, nothing to clean up.
            return new Response('', Response::HTTP_OK);
        }

        if (Request::METHOD_POST !== $request->getMethod()) {
            return new JsonResponse(null, Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST, DELETE, OPTIONS']);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->respond([JsonRpcResponse::error(null, JsonRpcError::parseError())], Response::HTTP_BAD_REQUEST);
        }

        return $this->dispatch($payload);
    }

    /**
     * @param array<mixed> $payload a single request object or a batch array
     */
    private function dispatch(array $payload): Response
    {
        // Batch request: a JSON array of requests.
        if (array_is_list($payload)) {
            $responses = [];
            foreach ($payload as $item) {
                if (!\is_array($item)) {
                    $responses[] = JsonRpcResponse::error(null, JsonRpcError::invalidRequest());
                    continue;
                }
                $response = $this->dispatchOne($item);
                if (null !== $response) {
                    $responses[] = $response;
                }
            }

            if ([] === $responses) {
                return new Response('', Response::HTTP_ACCEPTED);
            }

            return $this->respond($responses);
        }

        $response = $this->dispatchOne($payload);
        if (null === $response) {
            return new Response('', Response::HTTP_ACCEPTED);
        }

        return $this->respond([$response]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchOne(array $payload): ?JsonRpcResponse
    {
        try {
            $request = JsonRpcRequest::fromArray($payload);
        } catch (McpException $exception) {
            return JsonRpcResponse::error(null, $exception->error());
        }

        return $this->server->dispatch($request);
    }

    /**
     * @param list<JsonRpcResponse> $responses
     */
    private function respond(array $responses, int $status = Response::HTTP_OK): JsonResponse
    {
        $single = 1 === \count($responses) ? $responses[0] : null;

        return new JsonResponse(
            $single?->jsonSerialize() ?? array_map(static fn (JsonRpcResponse $r): array => $r->jsonSerialize(), $responses),
            $status,
            ['Content-Type' => 'application/json'],
        );
    }
}
