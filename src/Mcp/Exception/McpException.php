<?php

namespace App\Mcp\Exception;

use App\Mcp\Dto\JsonRpcError;

/**
 * A domain or protocol error that maps 1:1 onto a JSON-RPC error response.
 */
final class McpException extends \RuntimeException
{
    public function __construct(
        private readonly JsonRpcError $error,
    ) {
        parent::__construct($error->message);
    }

    public function error(): JsonRpcError
    {
        return $this->error;
    }
}
