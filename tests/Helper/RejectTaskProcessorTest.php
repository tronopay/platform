<?php

namespace App\Tests\Helper;

use ApiPlatform\Metadata\Post;
use App\Entity\Task;
use App\Helper\RejectTaskProcessor;

final class RejectTaskProcessorTest extends AbstractTaskActionProcessorTestCase
{
    public function testDelegatesRejectWithHeaderPassword(): void
    {
        $task = new Task();

        $processor = new RejectTaskProcessor(
            $this->clientApiExpecting('rejectTask', 'x', 'secret', $task),
        );

        $result = $processor->process($task, new Post(), ['id' => 'x'], ['request' => $this->requestWithPassword('secret')]);

        self::assertSame($task, $result);
    }

    public function testPassesEmptyPasswordWhenHeaderMissing(): void
    {
        $task = new Task();

        $processor = new RejectTaskProcessor(
            $this->clientApiExpecting('rejectTask', 'x', '', $task),
        );

        $processor->process($task, new Post(), ['id' => 'x'], ['request' => $this->requestWithPassword(null)]);
    }
}
