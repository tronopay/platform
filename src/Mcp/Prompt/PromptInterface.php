<?php

namespace App\Mcp\Prompt;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A templated MCP prompt. Implementations are auto-tagged with "app.mcp.prompt"
 * and collected by {@see PromptRegistry}.
 */
#[AutoconfigureTag('app.mcp.prompt')]
interface PromptInterface
{
    public function name(): string;

    public function description(): string;

    /**
     * @return list<array{name: string, description?: string, required?: bool}>
     */
    public function arguments(): array;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{messages: list<array{role: string, content: mixed}>}
     */
    public function get(array $arguments): array;
}
