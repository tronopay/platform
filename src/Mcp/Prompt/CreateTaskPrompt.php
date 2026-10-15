<?php

namespace App\Mcp\Prompt;

/**
 * Guides the LLM through turning a natural-language request into a create_task call.
 */
final class CreateTaskPrompt implements PromptInterface
{
    public function name(): string
    {
        return 'create_task';
    }

    public function description(): string
    {
        return 'Draft and submit a new Tronopay task from a natural-language description and a budget.';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'description', 'description' => 'What needs to be done, in natural language.', 'required' => true],
            ['name' => 'budget_trx', 'description' => 'Budget in TRX, for example "50".', 'required' => true],
            ['name' => 'type', 'description' => 'Task type: mark, verify, classify, compare, photo, video, audio, location, delivery or other.', 'required' => false],
            ['name' => 'location', 'description' => 'Optional coordinates as "latitude, longitude".', 'required' => false],
        ];
    }

    public function get(array $arguments): array
    {
        $description = (string) ($arguments['description'] ?? '');
        $budget = (string) ($arguments['budget_trx'] ?? '');
        $type = (string) ($arguments['type'] ?? 'other');
        $location = (string) ($arguments['location'] ?? '');

        $lines = [
            'You are helping a client create a task on Tronopay.',
            '',
            'Turn the request below into a single create_task tool call.',
            '',
            'Requirements:',
            '- Write a precise, self-contained "statement" that a worker can act on without further context.',
            '- Use the given budget as "price" (TRX). The minimum is 10 TRX.',
            '- Ask the client for a task password if none was provided; it must be 8-64 characters. '
                .'Every later action on the task (view result, accept, reject, cancel) requires it.',
            '- Pick the most fitting "type" from the allowed values.',
            '',
            sprintf('Description: %s', '' === $description ? '(not provided)' : $description),
            sprintf('Budget: %s TRX', '' === $budget ? '(not provided)' : $budget),
            sprintf('Type: %s', $type),
        ];

        if ('' !== $location) {
            $lines[] = sprintf('Location: %s', $location);
        }

        $lines[] = '';
        $lines[] = 'After the call, report the task id, the payment address and the amount to deposit.';

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
