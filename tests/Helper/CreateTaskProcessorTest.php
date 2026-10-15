<?php

namespace App\Tests\Helper;

use ApiPlatform\Metadata\Post;
use App\Dto\CreateTaskRequest;
use App\Dto\CreateTaskResponse;
use App\Entity\Task;
use App\Helper\CreateTaskProcessor;
use App\Service\TaskClientApiInterface;
use PHPUnit\Framework\TestCase;

final class CreateTaskProcessorTest extends TestCase
{
    public function testDelegatesToClientApi(): void
    {
        $request = new CreateTaskRequest();
        $request->statement = 'check the storefront';
        $request->password = 'plain-secret';

        $response = new CreateTaskResponse();

        $api = $this->createMock(TaskClientApiInterface::class);
        $api->expects($this->once())
            ->method('createTask')
            ->with($request)
            ->willReturn($response);

        $result = (new CreateTaskProcessor($api))->process($request, new Post());

        self::assertSame($response, $result);
    }

    public function testRejectsNonRequestData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $api = $this->createStub(TaskClientApiInterface::class);
        (new CreateTaskProcessor($api))->process(new Task(), new Post());
    }
}
