<?php

namespace App\Helper;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CreateTaskRequest;
use App\Dto\CreateTaskResponse;
use App\Service\TaskClientApiInterface;

/**
 * Creates a task through the shared {@see TaskClientApi} service, so the REST
 * API and the MCP server follow exactly the same rules.
 *
 * @implements ProcessorInterface<CreateTaskRequest, CreateTaskResponse>
 */
final readonly class CreateTaskProcessor implements ProcessorInterface
{
    public function __construct(
        private TaskClientApiInterface $clientApi,
    ) {
    }

    /**
     * @param CreateTaskRequest $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CreateTaskResponse
    {
        if (!$data instanceof CreateTaskRequest) {
            throw new \InvalidArgumentException('Expected a ' . CreateTaskRequest::class . ' instance, ' . get_debug_type($data) . ' given.');
        }

        return $this->clientApi->createTask($data);
    }
}
