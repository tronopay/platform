<?php

namespace App\Tests\Helper;

use App\Entity\Task;
use App\Service\TaskClientApiInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared helpers for the accept/reject/delete task processors, now that the
 * processors are thin delegates over {@see TaskClientApiInterface}.
 */
abstract class AbstractTaskActionProcessorTestCase extends TestCase
{
    protected function requestWithPassword(?string $password): Request
    {
        $server = null === $password ? [] : ['HTTP_X_TASK_PASSWORD' => $password];

        return Request::create('/api/tasks/00000000-0000-7000-8000-000000000000', 'POST', server: $server);
    }

    /**
     * Builds a TaskClientApi stub that expects exactly one call to the given
     * method with the given id + password and returns the task.
     */
    protected function clientApiExpecting(string $method, string $taskId, string $password, Task $task): TaskClientApiInterface
    {
        $api = $this->createMock(TaskClientApiInterface::class);
        $api->expects($this->once())
            ->method($method)
            ->with($taskId, $password)
            ->willReturn($task);

        return $api;
    }
}
