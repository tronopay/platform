<?php

namespace App\Mcp\Resource;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A provider of MCP resources (read-only, URI-addressed data). Implementations
 * are auto-tagged with "app.mcp.resource" and collected by {@see ResourceRegistry}.
 *
 * Tasks are runtime entities, so the task resources are exposed as URI
 * templates ({@see templates()}) rather than a static, exhaustive list.
 */
#[AutoconfigureTag('app.mcp.resource')]
interface ResourceInterface
{
    /**
     * Static resources (none for dynamic entities like tasks).
     *
     * @return list<array{uri: string, name: string, description?: string, mimeType?: string}>
     */
    public function list(): array;

    /**
     * RFC 6570 URI templates for dynamic resources.
     *
     * @return list<array{uriTemplate: string, name: string, description?: string, mimeType?: string}>
     */
    public function templates(): array;

    public function supports(string $uri): bool;

    /**
     * Reads one resource. $params carries the parsed query string (e.g. the
     * task password).
     *
     * @param array<string, mixed> $params
     *
     * @return list<array{uri: string, mimeType: string, text?: string, blob?: string}>
     */
    public function read(string $uri, array $params = []): array;
}
