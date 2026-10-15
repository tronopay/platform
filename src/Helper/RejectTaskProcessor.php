<?php

namespace App\Helper;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Task;
use App\Service\TaskClientApiInterface;

/**
 * Marks a task as rejected through the shared {@see TaskClientApi} service,
 * after verifying the task password sent in the X-Task-Password header.
 *
 * @implements ProcessorInterface<Task, Task>
 */
final readonly class RejectTaskProcessor implements ProcessorInterface
{
    public function __construct(
        private TaskClientApiInterface $clientApi,
    ) {
    }

    /**
     * @param Task $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Task
    {
        $taskId = (string) ($uriVariables['id'] ?? '');
        $password = (string) ($context['request']?->headers->get(TaskPasswordVerifier::HEADER) ?? '');

        return $this->clientApi->rejectTask($taskId, $password);
    }
}
