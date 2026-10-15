<?php

namespace App\Mcp\Server;

/**
 * Handles the MCP initialization handshake.
 */
final readonly class InitializeHandler
{
    /**
     * The MCP protocol version this server implements.
     */
    public const string PROTOCOL_VERSION = '2025-06-18';

    public const string SERVER_NAME = 'tronopay-mcp';

    public const string SERVER_VERSION = '1.0.0';

    /**
     * @param array<string, mixed> $params the "initialize" request params
     *
     * @return array<string, mixed>
     */
    public function initialize(array $params): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => 'Tronopay task-creation API over MCP. Tools create, cancel, accept and reject tasks; '
                .'resources (task://...) expose task, invoice, result and file data. Password-protected operations '
                .'take the task password as an argument.',
        ];
    }
}
