<?php

namespace App\Mcp\Tool;

/**
 * Lists all tasks, newest first, with pagination.
 */
final class ListTasksTool extends AbstractTaskTool
{
    public function name(): string
    {
        return 'list_tasks';
    }

    public function description(): string
    {
        return 'List all tasks, newest first, with pagination. Returns task metadata (id, statement, status, type, price, '
            .'timestamps). Does not require a task password — only public fields are shown.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Page number (1-based). Default: 1.',
                ],
                'per' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'description' => 'Tasks per page. Default: 30.',
                ],
            ],
        ];
    }

    public function call(array $arguments): array
    {
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $per = max(1, min((int) ($arguments['per'] ?? 30), 100));

        $result = $this->clientApi->listTasks($page, $per);

        return [
            'tasks' => $this->normalizer->tasks($result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'per' => $result['per'],
            'totalPages' => $result['totalPages'],
        ];
    }
}