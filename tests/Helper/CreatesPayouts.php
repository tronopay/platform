<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Entity\Payout;
use App\Entity\PayoutDirection;
use App\Entity\PayoutFailureKind;
use App\Entity\PayoutStatus;
use App\Entity\Task;
use App\Entity\TaskStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists the minimum task/payout rows the payout operator commands work on.
 */
trait CreatesPayouts
{
    private function createPayoutTask(
        EntityManagerInterface $em,
        TaskStatus $status = TaskStatus::Accepted,
        int $priceSun = 10_000_000,
        ?string $refundAddress = null,
        string $escrow = 'TEsUWfRqtr6jmjHuxvrEMVzoSh5Mz52TzT',
    ): Task {
        $task = new Task();
        $task->setStatement('Payout operator test task.');
        $task->setPassword('task-password');
        $task->setStatus($status);
        $task->setPriceSun($priceSun);
        $task->setRefundAddress($refundAddress);
        $task->getWallet()?->setAddress($escrow);

        $em->persist($task);
        $em->flush();

        return $task;
    }

    private function createPayoutRow(
        EntityManagerInterface $em,
        Task $task,
        PayoutStatus $status,
        ?PayoutFailureKind $failureKind = null,
        ?\DateTimeImmutable $broadcastAt = null,
        ?\DateTimeImmutable $confirmedAt = null,
        string $toAddress = 'THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY',
        ?string $batchId = null,
    ): Payout {
        $payout = new Payout(task: $task);
        $payout
            ->setDirection(PayoutDirection::Worker)
            ->setFromAddress('TEsUWfRqtr6jmjHuxvrEMVzoSh5Mz52TzT')
            ->setToAddress($toAddress)
            ->setAmountSun((int) $task->getPriceSun())
            ->setWalletIndex(3)
            ->setStatus($status)
            ->setFailureKind($failureKind)
            ->setPreparedAt(new \DateTimeImmutable());

        if (null !== $batchId) {
            $payout->setBatchId($batchId);
        }

        if (null !== $broadcastAt) {
            $payout->setBroadcastAt($broadcastAt);
        }

        if (null !== $confirmedAt) {
            $payout->setConfirmedAt($confirmedAt);
        }

        $em->persist($payout);
        $em->flush();

        return $payout;
    }
}
