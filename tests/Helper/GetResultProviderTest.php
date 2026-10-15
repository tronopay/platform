<?php

namespace App\Tests\Helper;

use ApiPlatform\Metadata\Get;
use App\Dto\GetTaskResultResponse;
use App\Helper\GetResultProvider;
use App\Service\TaskClientApiInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class GetResultProviderTest extends TestCase
{
    public function testDelegatesToClientApiWithHeaderPassword(): void
    {
        $result = new GetTaskResultResponse();

        $api = $this->createMock(TaskClientApiInterface::class);
        $api->expects($this->once())
            ->method('getResult')
            ->with('x', 'secret')
            ->willReturn($result);

        $provider = new GetResultProvider($api);

        $actual = $provider->provide(new Get(), ['id' => 'x'], ['request' => $this->request('secret')]);

        self::assertSame($result, $actual);
    }

    private function request(string $password): Request
    {
        return Request::create('/api/tasks/x', server: ['HTTP_X_TASK_PASSWORD' => $password]);
    }
}
