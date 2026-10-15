<?php

namespace App\Helper;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\GetTaskResultResponse;
use App\Service\TaskClientApiInterface;

/**
 * Returns the result of a task through the shared {@see TaskClientApi} service,
 * after verifying the task password.
 *
 * @implements ProviderInterface<GetTaskResultResponse>
 */
final readonly class GetResultProvider implements ProviderInterface
{
    public function __construct(
        private TaskClientApiInterface $clientApi,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GetTaskResultResponse
    {
        $taskId = (string) ($uriVariables['id'] ?? '');
        $password = (string) ($context['request']?->headers->get(TaskPasswordVerifier::HEADER) ?? '');

        return $this->clientApi->getResult($taskId, $password);
    }
}
