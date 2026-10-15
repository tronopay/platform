<?php

namespace App\Tests\Helper;

use ApiPlatform\Metadata\Post;
use App\Entity\Task;
use App\Helper\AcceptTaskProcessor;

final class AcceptTaskProcessorTest extends AbstractTaskActionProcessorTestCase
{
    public function testDelegatesAcceptWithHeaderPassword(): void
    {
        $task = new Task();

        $processor = new AcceptTaskProcessor(
            $this->clientApiExpecting('acceptTask', 'x', 'secret', $task),
        );

        $result = $processor->process($task, new Post(), ['id' => 'x'], ['request' => $this->requestWithPassword('secret')]);

        self::assertSame($task, $result);
    }

    public function testPassesEmptyPasswordWhenHeaderMissing(): void
    {
        $task = new Task();

        $processor = new AcceptTaskProcessor(
            $this->clientApiExpecting('acceptTask', 'x', '', $task),
        );

        $processor->process($task, new Post(), ['id' => 'x'], ['request' => $this->requestWithPassword(null)]);
    }
}
