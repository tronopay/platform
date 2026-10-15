<?php

namespace App\Mcp\Tool;

use App\Dto\CreateTaskRequest;
use App\Entity\TaskType;
use App\Mcp\Dto\JsonRpcError;
use App\Mcp\Exception\McpException;

/**
 * Creates a task: the client describes what must be done, sets a budget in TRX,
 * and receives a payment-address invoice.
 */
final class CreateTaskTool extends AbstractTaskTool
{
    public function name(): string
    {
        return 'create_task';
    }

    public function description(): string
    {
        return 'Create a new task on Tronopay. Returns the task id, status, payment address, required TRX deposit and a human-readable hint. '
            .'Save the task id and password — all later actions (view invoice, view result, accept, reject, cancel) need them.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'statement' => [
                    'type' => 'string',
                    'description' => 'Task description / statement. What needs to be done.',
                ],
                'password' => [
                    'type' => 'string',
                    'minLength' => 8,
                    'maxLength' => 64,
                    'description' => 'Password to protect the task. Required for all later actions on this task (get result, accept, reject, cancel). Minimum 8 characters.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => array_map(fn (TaskType $t): string => $t->value, TaskType::cases()),
                    'description' => 'Task type. Default: other.',
                ],
                'price' => [
                    'type' => 'string',
                    'description' => 'Price in TRX. Minimum 10. Example: "25.5" or "100". Default: 10.',
                ],
                'location' => [
                    'type' => 'string',
                    'description' => 'Optional coordinates in "latitude, longitude" format. Example: "51.500675, -0.143105".',
                ],
                'refund_address' => [
                    'type' => 'string',
                    'description' => 'Optional TRON address (base58) for refunds if the task is rejected.',
                ],
                'webhook' => [
                    'type' => 'string',
                    'description' => 'Optional HTTPS URL to notify on status changes.',
                ],
            ],
            'required' => ['statement', 'password'],
        ];
    }

    public function call(array $arguments): array
    {
        $request = new CreateTaskRequest();
        $request->statement = $this->requireString($arguments, 'statement');
        $request->password = $this->requireString($arguments, 'password');

        $rawType = $arguments['type'] ?? 'other';
        $type = TaskType::tryFrom((string) $rawType);
        if (null === $type) {
            throw new McpException(JsonRpcError::validationFailed([
                sprintf('type: invalid value "%s". Allowed: %s', $rawType, implode(', ', array_map(fn (TaskType $t): string => $t->value, TaskType::cases()))),
            ]));
        }
        $request->type = $type;

        if (isset($arguments['price'])) {
            $request->price = (string) $arguments['price'];
        }

        $location = $this->optionalString($arguments, 'location');
        if (null !== $location) {
            $request->setLocation($location);
        }

        $refund = $this->optionalString($arguments, 'refund_address');
        if (null !== $refund) {
            $request->refundAddress = $refund;
        }

        $webhook = $this->optionalString($arguments, 'webhook');
        if (null !== $webhook) {
            $request->webhook = $webhook;
        }

        try {
            $response = $this->clientApi->createTask($request);
        } catch (\Throwable $e) {
            throw $this->mapException($e);
        }

        return $this->normalizer->created($response);
    }
}