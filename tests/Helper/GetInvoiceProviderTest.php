<?php

namespace App\Tests\Helper;

use ApiPlatform\Metadata\Get;
use App\Dto\GetTaskInvoiceResponse;
use App\Helper\GetInvoiceProvider;
use App\Service\TaskClientApiInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class GetInvoiceProviderTest extends TestCase
{
    public function testDelegatesToClientApiWithHeaderPassword(): void
    {
        $invoice = new GetTaskInvoiceResponse();

        $api = $this->createMock(TaskClientApiInterface::class);
        $api->expects($this->once())
            ->method('getInvoice')
            ->with('x', 'secret')
            ->willReturn($invoice);

        $provider = new GetInvoiceProvider($api);

        $result = $provider->provide(new Get(), ['id' => 'x'], ['request' => $this->request('secret')]);

        self::assertSame($invoice, $result);
    }

    private function request(string $password): Request
    {
        return Request::create('/api/tasks/x', server: ['HTTP_X_TASK_PASSWORD' => $password]);
    }
}
