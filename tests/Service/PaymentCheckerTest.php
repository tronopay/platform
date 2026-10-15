<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\PaymentChecker;
use App\Service\TaskService;
use App\Service\TronGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PaymentCheckerTest extends TestCase
{
    public function testMarksPaidWhenTransferMeetsPrice(): void
    {
        $task = new Task();
        $task->getWallet()->setAddress('THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY');
        $task->setPriceSun(10_000_000);
        $deadline = (new \DateTimeImmutable())->modify('+2 hours');
        $task->setPaymentDeadlineAt($deadline);

        $gateway = $this->createMock(TronGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('incomingTransfers')
            ->with($task->getWallet()->getAddress(), $task->getCreatedAt(), $deadline)
            ->willReturn([['txId' => 'tx-abc', 'amount' => 10_000_000]]);

        $taskService = $this->createMock(TaskService::class);
        $taskService->expects($this->once())
            ->method('move')
            ->with($this->identicalTo($task), 'to_prepaid');

        $repo = $this->createMock(TaskRepository::class);
        $repo->expects($this->exactly(2))
            ->method('findPendingPaymentTasksAfter')
            ->willReturnOnConsecutiveCalls([$task], []);

        $checker = new PaymentChecker($repo, $taskService, $gateway, new NullLogger(), 10);
        $count = $checker->processPending(new \DateTimeImmutable());

        self::assertSame(1, $count);
        self::assertSame('tx-abc', $task->getPaymentTxHash());
        self::assertNotNull($task->getPaidAt());
    }

    public function testDoesNotMarkPaidWhenTransferBelowPrice(): void
    {
        $task = new Task();
        $task->getWallet()->setAddress('THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY');
        $task->setPriceSun(10_000_000);
        $task->setPaymentDeadlineAt((new \DateTimeImmutable())->modify('+2 hours'));

        $gateway = $this->createMock(TronGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('incomingTransfers')
            ->willReturn([['txId' => 'tx-low', 'amount' => 5_000_000]]);

        $taskService = $this->createMock(TaskService::class);
        $taskService->expects($this->never())->method('move');

        $repo = $this->createMock(TaskRepository::class);
        $repo->expects($this->exactly(2))
            ->method('findPendingPaymentTasksAfter')
            ->willReturnOnConsecutiveCalls([$task], []);

        $checker = new PaymentChecker($repo, $taskService, $gateway, new NullLogger(), 10);
        $count = $checker->processPending(new \DateTimeImmutable());

        self::assertSame(0, $count);
        self::assertNull($task->getPaymentTxHash());
        self::assertNull($task->getPaidAt());
    }

    public function testLogsIndexerFailureAndKeepsTaskPending(): void
    {
        $task = new Task();
        $task->getWallet()->setAddress('THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY');
        $task->setPriceSun(10_000_000);
        $task->setPaymentDeadlineAt((new \DateTimeImmutable())->modify('+2 hours'));

        $gateway = $this->createStub(TronGatewayInterface::class);
        $gateway->method('incomingTransfers')
            ->willThrowException(new \RuntimeException('indexer down'));

        $taskService = $this->createMock(TaskService::class);
        $taskService->expects($this->never())->method('move');

        $repo = $this->createMock(TaskRepository::class);
        $repo->expects($this->exactly(2))
            ->method('findPendingPaymentTasksAfter')
            ->willReturnOnConsecutiveCalls([$task], []);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to read incoming transfers.',
                $this->callback(static fn (array $context): bool => ($context['exception'] ?? null) instanceof \RuntimeException),
            );

        $checker = new PaymentChecker($repo, $taskService, $gateway, $logger, 10);
        $count = $checker->processPending(new \DateTimeImmutable());

        self::assertSame(0, $count);
        self::assertNull($task->getPaidAt());
    }

    public function testCancelsExpiredTasks(): void
    {
        $task = new Task();
        $task->setPaymentDeadlineAt((new \DateTimeImmutable())->modify('-1 hour'));

        $taskService = $this->createMock(TaskService::class);
        $taskService->expects($this->once())
            ->method('move')
            ->with($this->identicalTo($task), 'to_canceled_created');

        $repo = $this->createMock(TaskRepository::class);
        $repo->expects($this->exactly(2))
            ->method('findExpiredPaymentTasksAfter')
            ->willReturnOnConsecutiveCalls([$task], []);

        $checker = new PaymentChecker($repo, $taskService, $this->createStub(TronGatewayInterface::class), new NullLogger(), 10);
        $count = $checker->cancelExpired(new \DateTimeImmutable());

        self::assertSame(1, $count);
    }
}