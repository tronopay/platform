<?php

namespace App\Mcp\Transport;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transport for MCP messages over HTTP. Implementations map an HTTP request to
 * the JSON-RPC dispatcher and back to an HTTP response.
 */
interface TransportInterface
{
    public function handle(Request $request): Response;
}
