<?php

namespace App\Helper;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\TaskClientApiInterface;
use App\Storage\FileStorageInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Streams a single result file of a task to its owner through the shared
 * {@see TaskClientApi} service, after verifying the task password.
 *
 * The payload is the raw file, so the provider returns a Symfony Response:
 * API Platform's processors leave a Response untouched, which keeps the bytes
 * out of the serializer.
 *
 * @implements ProviderInterface<Response>
 */
final readonly class GetTaskResultFileProvider implements ProviderInterface
{
    public function __construct(
        private TaskClientApiInterface $clientApi,
        private FileStorageInterface $storage,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $taskId = (string) ($uriVariables['id'] ?? '');
        $fileId = (string) ($uriVariables['fileId'] ?? '');
        $password = (string) ($context['request']?->headers->get(TaskPasswordVerifier::HEADER) ?? '');

        $file = $this->clientApi->getResultFile($taskId, $fileId, $password);

        $response = new Response($this->storage->get((string) $file->getVirtualPath()));
        $response->headers->set('Content-Type', (string) $file->getMimeType());
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            (string) $file->getOriginalName(),
        ));

        return $response;
    }
}
