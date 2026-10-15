<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use App\Repository\TaskRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Detects incoming prepayments and transitions created tasks accordingly.
 */
final readonly class PaymentChecker
{
    public function __construct(
        private TaskRepository $tasks,
        private TaskService $taskService,
        private TronGatewayInterface $tronGateway,
        private LoggerInterface $logger,
        #[Autowire('%env(int:PAYMENT_BATCH_SIZE)%')]
        private int $batchSize = 50,
    ) {
    }

    /**
     * Cancels tasks whose payment window has passed and returns the number cancelled.
     */
    public function cancelExpired(\DateTimeImmutable $now): int
    {
        $count = 0;
        $lastId = null;

        while (true) {
            $batch = $this->tasks->findExpiredPaymentTasksAfter($now, $lastId, $this->batchSize);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $task) {
                try {
                    $this->taskService->move($task, 'to_canceled_created');
                    ++$count;
                } catch (\Throwable) {
                    // A concurrent run may already have moved this task.
                }
            }

            $lastId = $batch[\count($batch) - 1]->getId();
        }

        return $count;
    }

    /**
     * Marks created tasks as prepaid once a sufficient transfer arrives.
     *
     * @return int number of tasks that became prepaid
     */
    public function processPending(\DateTimeImmutable $now): int
    {
        $count = 0;
        $lastId = null;

        while (true) {
            $batch = $this->tasks->findPendingPaymentTasksAfter($now, $lastId, $this->batchSize);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $task) {
                if ($this->markPaidIfReceived($task, $now)) {
                    ++$count;
                }
            }

            $lastId = $batch[\count($batch) - 1]->getId();
        }

        return $count;
    }

    private function markPaidIfReceived(Task $task, \DateTimeImmutable $now): bool
    {
        $address = $task->getWallet()?->getAddress();
        if ($address === null || $address === '') {
            return false;
        }

        try {
            $transfers = $this->tronGateway->incomingTransfers(
                $address,
                $task->getCreatedAt() ?? $now,
                $task->getPaymentDeadlineAt(),
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to read incoming transfers.', ['exception' => $throwable]);
            // Indexer hiccups must not abort the whole payment cycle.
            return false;
        }

        foreach ($transfers as $transfer) {
            if ($transfer['amount'] >= $task->getPriceSun()) {
                $task->setPaidAt($now);
                $task->setPaymentTxHash($transfer['txId']);

                $this->taskService->move($task, 'to_prepaid');

                return true;
            }
        }

        return false;
    }
}
