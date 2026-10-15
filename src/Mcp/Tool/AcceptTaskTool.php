<?php

namespace App\Mcp\Tool;

/**
 * Accepts a processed task result, releasing the escrow to the worker.
 */
final class AcceptTaskTool extends AbstractTaskTool
{
    public function name(): string
    {
        return 'accept_task';
    }

    public function description(): string
    {
        return 'Accept the worker\'s result and release the escrow to the worker. Requires the task id and password. '
            .'Only tasks in "processed" status (worker submitted a result) can be accepted. '
            .'The actual payout happens later via the payout process — accepting queues the task for payout.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'string',
                    'description' => 'The task UUID.',
                ],
                'password' => [
                    'type' => 'string',
                    'description' => 'The task password.',
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
            $task = $this->clientApi->acceptTask($taskId, $password);
        } catch (\Throwable $e) {
            throw $this->mapException($e, $taskId);
        }

        return $this->normalizer->task($task);
    }
}