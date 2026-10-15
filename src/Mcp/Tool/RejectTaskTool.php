<?php

namespace App\Mcp\Tool;

/**
 * Rejects a processed task result, requesting an escrow refund.
 */
final class RejectTaskTool extends AbstractTaskTool
{
    public function name(): string
    {
        return 'reject_task';
    }

    public function description(): string
    {
        return 'Reject the worker\'s result and request a refund of the escrow. Requires the task id and password. '
            .'Only tasks in "processed" status (worker submitted a result) can be rejected. '
            .'The refund happens later via the payout process — rejecting queues the task for refund.';
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
            $task = $this->clientApi->rejectTask($taskId, $password);
        } catch (\Throwable $e) {
            throw $this->mapException($e, $taskId);
        }

        return $this->normalizer->task($task);
    }
}