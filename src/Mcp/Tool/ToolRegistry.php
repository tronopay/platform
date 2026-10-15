<?php

namespace App\Mcp\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects and routes the MCP tools.
 */
final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools;

    /**
     * @param iterable<ToolInterface> $tools
     */
    public function __construct(
        #[AutowireIterator('app.mcp.tool')]
        iterable $tools,
    ) {
        $indexed = [];
        foreach ($tools as $tool) {
            $indexed[$tool->name()] = $tool;
        }
        $this->tools = $indexed;
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function list(): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }

        // Stable ordering by name.
        usort($tools, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $tools;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ToolInterface
    {
        if (!isset($this->tools[$name])) {
            throw new \OutOfRangeException(sprintf('Unknown tool "%s".', $name));
        }

        return $this->tools[$name];
    }

    public function isEmpty(): bool
    {
        return [] === $this->tools;
    }
}
