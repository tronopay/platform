<?php

namespace App\Helper;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\GetTaskInvoiceResponse;
use App\Service\TaskClientApiInterface;

/**
 * Builds the prepayment invoice for a task through the shared
 * {@see TaskClientApi} service, after verifying the task password.
 *
 * @implements ProviderInterface<GetTaskInvoiceResponse>
 */
final readonly class GetInvoiceProvider implements ProviderInterface
{
    public function __construct(
        private TaskClientApiInterface $clientApi,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GetTaskInvoiceResponse
    {
        $taskId = (string) ($uriVariables['id'] ?? '');
        $password = (string) ($context['request']?->headers->get(TaskPasswordVerifier::HEADER) ?? '');

        return $this->clientApi->getInvoice($taskId, $password);
    }
}
