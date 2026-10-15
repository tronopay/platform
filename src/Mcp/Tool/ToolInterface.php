<?php

namespace App\Mcp\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One MCP tool, exposed to the LLM client. Implementations are auto-tagged
 * with "app.mcp.tool" and collected by {@see ToolRegistry}.
 */
#[AutoconfigureTag('app.mcp.tool')]
interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON Schema of the tool's named arguments.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Executes the tool and returns the structured payload serialized as JSON
     * text in the tool result.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function call(array $arguments): array;
}
