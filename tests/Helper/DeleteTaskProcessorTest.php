<?php

namespace App\Tests\Helper;

use ApiPlatform\Metadata\Delete;
use App\Entity\Task;
use App\Helper\DeleteTaskProcessor;

final class DeleteTaskProcessorTest extends AbstractTaskActionProcessorTestCase
{
    public function testDelegatesCancelWithHeaderPassword(): void
    {
        $task = new Task();

        $processor = new DeleteTaskProcessor(
            $this->clientApiExpecting('cancelTask', 'x', 'secret', $task),
        );

        $result = $processor->process($task, new Delete(), ['id' => 'x'], ['request' => $this->requestWithPassword('secret')]);

        self::assertSame($task, $result);
    }

    public function testPassesEmptyPasswordWhenHeaderMissing(): void
    {
        $task = new Task();

        $processor = new DeleteTaskProcessor(
            $this->clientApiExpecting('cancelTask', 'x', '', $task),
        );

        $processor->process($task, new Delete(), ['id' => 'x'], ['request' => $this->requestWithPassword(null)]);
    }
}
