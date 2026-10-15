<?php

namespace App\Mcp\Resource;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects and routes the MCP resources.
 */
final class ResourceRegistry
{
    /** @var list<ResourceInterface> */
    private array $providers;

    /**
     * @param iterable<ResourceInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.mcp.resource')]
        iterable $providers,
    ) {
        $this->providers = \is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
    }

    /**
     * @return list<array{uri: string, name: string, description?: string, mimeType?: string}>
     */
    public function list(): array
    {
        $resources = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->list() as $resource) {
                $resources[] = $resource;
            }
        }

        usort($resources, static fn (array $a, array $b): int => strcmp($a['uri'], $b['uri']));

        return $resources;
    }

    /**
     * @return list<array{uriTemplate: string, name: string, description?: string, mimeType?: string}>
     */
    public function templates(): array
    {
        $templates = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->templates() as $template) {
                $templates[] = $template;
            }
        }

        usort($templates, static fn (array $a, array $b): int => strcmp($a['uriTemplate'], $b['uriTemplate']));

        return $templates;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array{uri: string, mimeType: string, text?: string, blob?: string}>
     */
    public function read(string $uri, array $params = []): array
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($uri)) {
                return $provider->read($uri, $params);
            }
        }

        throw new \OutOfRangeException(sprintf('No provider for resource "%s".', $uri));
    }

    public function isEmpty(): bool
    {
        return [] === $this->providers;
    }
}
