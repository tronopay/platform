<?php

namespace App\Mcp\Prompt;

/**
 * Guides the LLM through inspecting a task and suggesting the next step.
 */
final class CheckTaskPrompt implements PromptInterface
{
    public function name(): string
    {
        return 'check_task';
    }

    public function description(): string
    {
        return 'Inspect a task\'s current state (invoice, result) and recommend the next action.';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'task_id', 'description' => 'The task UUID.', 'required' => true],
            ['name' => 'password', 'description' => 'The task password (needed for invoice and result).', 'required' => false],
        ];
    }

    public function get(array $arguments): array
    {
        $taskId = (string) ($arguments['task_id'] ?? '');
        $password = (string) ($arguments['password'] ?? '');

        $lines = [
            'Inspect the Tronopay task below and tell the client what to do next.',
            '',
            sprintf('Task id: %s', $taskId),
        ];

        if ('' !== $password) {
            $lines[] = sprintf(
                'Read resources "task://%s", "task://%s/invoice?password=%s" and, when the status is "processed", '
                .'"task://%s/result?password=%s".',
                $taskId,
                $taskId,
                $password,
                $taskId,
                $password,
            );
        } else {
            $lines[] = sprintf(
                'Read resource "task://%s". Ask the client for the task password before reading the invoice or the result.',
                $taskId,
            );
        }

        $lines[] = '';
        $lines[] = 'Then recommend exactly one next step based on the status:';
        $lines[] = '- created: deposit the quoted TRX to the payment address before the deadline, or cancel the task.';
        $lines[] = '- prepaid: wait for a worker to pick the task up.';
        $lines[] = '- assigned: a worker is on it; wait.';
        $lines[] = '- processed: review the result, then accept_task or reject_task.';
        $lines[] = '- accepted / rejected: the escrow is queued for payout; nothing left to do.';
        $lines[] = '- canceled / finished: the task is closed.';

        return [
            'description' => $this->description(),
            'messages' => [[
                'role' => 'user',
                'content' => [
                    'type' => 'text',
                    'text' => implode("\n", $lines),
                ],
            ]],
        ];
    }
}
