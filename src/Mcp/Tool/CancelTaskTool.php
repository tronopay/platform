<?php

namespace App\Mcp\Tool;

/**
 * Cancels (soft-deletes) a task. Only tasks in the "created" (not yet paid) status
 * can be cancelled.
 */
final class CancelTaskTool extends AbstractTaskTool
{
    public function name(): string
    {
        return 'cancel_task';
    }

    public function description(): string
    {
        return 'Cancel (soft-delete) a task. Requires the task id and password. Only tasks that have not been '
            .'paid yet (status "created") can be cancelled. Returns the updated task.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'string',
                    'description' => 'The task UUID (returned by create_task).',
                ],
                'password' => [
                    'type' => 'string',
                    'description' => 'The task password specified when the task was created.',
                ],
            ],
            'required' => ['task_id', 'password'],
        ];
    }

    public function call(array $arguments): array
    {
        $taskId = $this->requireString($arguments, 'task_id');
        $password = $this->requireString($arguments, 'password');

        try {
            $task = $this->clientApi->cancelTask($taskId, $password);
        } catch (\Throwable $e) {
            throw $this->mapException($e, $taskId);
        }

        return $this->normalizer->task($task);
    }
}