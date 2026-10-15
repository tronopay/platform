<?php

namespace App\Mcp\Prompt;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects and routes the MCP prompts.
 */
final class PromptRegistry
{
    /** @var array<string, PromptInterface> */
    private array $prompts;

    /**
     * @param iterable<PromptInterface> $prompts
     */
    public function __construct(
        #[AutowireIterator('app.mcp.prompt')]
        iterable $prompts,
    ) {
        $indexed = [];
        foreach ($prompts as $prompt) {
            $indexed[$prompt->name()] = $prompt;
        }
        $this->prompts = $indexed;
    }

    /**
     * @return list<array{name: string, description: string, arguments: array}>
     */
    public function list(): array
    {
        $prompts = [];
        foreach ($this->prompts as $prompt) {
            $prompts[] = [
                'name' => $prompt->name(),
                'description' => $prompt->description(),
                'arguments' => $prompt->arguments(),
            ];
        }

        usort($prompts, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $prompts;
    }

    public function has(string $name): bool
    {
        return isset($this->prompts[$name]);
    }

    public function get(string $name): PromptInterface
    {
        if (!isset($this->prompts[$name])) {
            throw new \OutOfRangeException(sprintf('Unknown prompt "%s".', $name));
        }

        return $this->prompts[$name];
    }

    public function isEmpty(): bool
    {
        return [] === $this->prompts;
    }
}
