<?php

namespace App\Mcp\Resource;

/**
 * Task invoice (password required): task://{id}/invoice?password=...
 */
final class TaskInvoiceResourceProvider extends AbstractTaskResource
{
    public function templates(): array
    {
        return [[
            'uriTemplate' => 'task://{id}/invoice?password={password}',
            'name' => 'Task invoice',
            'description' => 'Payment invoice: amount in TRX, payment address, status and hint. Requires the task password.',
            'mimeType' => 'application/json',
        ]];
    }

    public function supports(string $uri): bool
    {
        return 1 === preg_match('#^task://(' . self::UUID . ')/invoice$#', $uri);
    }

    public function read(string $uri, array $params = []): array
    {
        preg_match('#^task://(' . self::UUID . ')/invoice$#', $uri, $m);
        $taskId = $m[1] ?? '';

        try {
            $invoice = $this->clientApi->getInvoice($taskId, $this->password($params));
        } catch (\Throwable $e) {
            throw $this->mapException($e, $taskId);
        }

        return [[
            'uri' => $uri,
            'mimeType' => 'application/json',
            'text' => $this->json($this->normalizer->created($invoice)),
        ]];
    }
}
