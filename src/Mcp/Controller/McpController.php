<?php

namespace App\Mcp\Controller;

use App\Mcp\Transport\TransportInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Single MCP endpoint. Streamable HTTP transport handles POST (JSON-RPC) and
 * DELETE (session teardown); it is stateless, so no server-side session is kept.
 *
 * @see docs/mcp-server-design.md
 */
#[Route('/mcp', name: 'app_mcp')]
final readonly class McpController
{
    public function __construct(
        private TransportInterface $transport,
        #[Autowire('%env(bool:MCP_ENABLED)%')]
        private bool $enabled = true,
    ) {
    }

    #[Route('', name: '_post', methods: ['POST'])]
    public function post(Request $request): Response
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException();
        }

        return $this->transport->handle($request);
    }

    #[Route('', name: '_delete', methods: ['DELETE'])]
    public function delete(Request $request): Response
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException();
        }

        return $this->transport->handle($request);
    }

    private function createNotFoundException(): NotFoundHttpException
    {
        return new NotFoundHttpException('The MCP endpoint is disabled.');
    }
}
