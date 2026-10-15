<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\TaskRepository;
use App\Service\PaymentChecker;
use App\Service\PaymentProcessor;
use App\Service\TaskService;
use App\Service\TronGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class PaymentProcessorTest extends TestCase
{
    public function testProcessMergesCounts(): void
    {
        $repo = $this->createMock(TaskRepository::class);
        $repo->method('findPendingPaymentTasksAfter')->willReturn([]);
        $repo->method('findExpiredPaymentTasksAfter')->willReturn([]);

        $processor = new PaymentProcessor(
            $this->createChecker($repo),
            new LockFactory(new InMemoryStore()),
        );

        self::assertSame(['prepaid' => 0, 'cancelled' => 0], $processor->process());
    }

    public function testSkipsWhenLockIsAlreadyHeld(): void
    {
        $factory = new LockFactory(new InMemoryStore());

        // Another run is in progress for the same key.
        $held = $factory->createLockFromKey(new Key('app.payment-process'));
        $held->acquire();

        $repo = $this->createMock(TaskRepository::class);
        $repo->expects($this->never())->method('findPendingPaymentTasksAfter');
        $repo->expects($this->never())->method('findExpiredPaymentTasksAfter');

        $processor = new PaymentProcessor($this->createChecker($repo), $factory);

        self::assertNull($processor->process());

        $held->release();
    }

    private function createChecker(TaskRepository $repo): PaymentChecker
    {
        return new PaymentChecker(
            $repo,
            $this->createStub(TaskService::class),
            $this->createStub(TronGatewayInterface::class),
            new NullLogger(),
            10,
        );
    }
}
