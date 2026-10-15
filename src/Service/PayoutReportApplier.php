<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Payout;
use App\Entity\PayoutFailureKind;
use App\Entity\PayoutStatus;
use App\Repository\PayoutRepository;
use App\ValueObject\PayoutIntentBundle;
use App\ValueObject\PayoutReportApplyResult;
use App\ValueObject\PayoutReportBundle;
use App\ValueObject\PayoutReportItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The server side of the report: folds the machine's report back into the
 * database rows, idempotently.
 *
 * A settled row (broadcast/confirmed) is never touched. Every report item is
 * checked against the intent the server wrote (taskId, direction, from/to and
 * amount) so the server never counts money as sent based on foreign data; a
 * mismatch forces the payout to `failed/permanent` and logs it instead.
 */
final readonly class PayoutReportApplier
{
    public function __construct(
        private PayoutRepository $payouts,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function apply(
        PayoutIntentBundle $intent,
        PayoutReportBundle $report,
        \DateTimeImmutable $now,
        bool $dryRun = false,
    ): PayoutReportApplyResult {
        $intentByPayoutId = [];
        foreach ($intent->items as $item) {
            $intentByPayoutId[$item->payoutId] = $item;
        }

        $applied = [];
        $skipped = [];
        $failed = [];

        foreach ($report->items as $reportItem) {
            $payout = $this->findPayout($reportItem->payoutId);
            if (null === $payout) {
                $failed[] = ['taskId' => $reportItem->taskId, 'payoutId' => $reportItem->payoutId, 'error' => 'no matching payout row'];
                continue;
            }

            if ($payout->getStatus()->isSettled()) {
                $skipped[] = ['taskId' => $reportItem->taskId, 'payoutId' => $reportItem->payoutId, 'reason' => 'already settled'];
                continue;
            }

            $intentItem = $intentByPayoutId[$reportItem->payoutId] ?? null;
            $mismatch = $this->mismatch($payout, $reportItem, $intentItem);
            if (null !== $mismatch) {
                if (!$dryRun) {
                    $this->markFailed($payout, $now, $mismatch);
                }
                $failed[] = ['taskId' => $reportItem->taskId, 'payoutId' => $reportItem->payoutId, 'error' => $mismatch];
                continue;
            }

            if (!$dryRun) {
                $this->applyOutcome($payout, $reportItem, $now);
                $this->entityManager->persist($payout);
            }

            $applied[] = [
                'taskId' => $reportItem->taskId,
                'payoutId' => $reportItem->payoutId,
                'status' => $reportItem->outcome->toStatus()->value,
            ];
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return new PayoutReportApplyResult($report->batchId, $applied, $skipped, $failed, $dryRun);
    }

    private function findPayout(string $payoutId): ?Payout
    {
        if (!Uuid::isValid($payoutId)) {
            return null;
        }

        return $this->payouts->findOneBy(['id' => Uuid::fromString($payoutId)]);
    }

    private function mismatch(Payout $payout, PayoutReportItem $reportItem, mixed $intentItem): ?string
    {
        if (null === $intentItem) {
            return 'the report item is not part of the intent';
        }

        $taskId = (string) $payout->getTask()?->getId();
        if ($taskId !== $reportItem->taskId || $taskId !== $intentItem->taskId) {
            return 'the report task id does not match the intent';
        }

        if ($payout->getDirection()?->value !== $intentItem->direction) {
            return 'the payout direction does not match the intent';
        }

        if ($payout->getFromAddress() !== $intentItem->fromAddress) {
            return 'the escrow address does not match the intent';
        }

        if ($payout->getToAddress() !== $intentItem->toAddress) {
            return 'the recipient address does not match the intent';
        }

        if ($payout->getAmountSun() !== $intentItem->amountSun) {
            return 'the amount does not match the intent';
        }

        return null;
    }

    private function applyOutcome(Payout $payout, PayoutReportItem $reportItem, \DateTimeImmutable $now): void
    {
        $payout
            ->setTxId($reportItem->txId)
            ->setFeeSun($reportItem->feeSun)
            ->setBlockNumber($reportItem->blockNumber);

        switch ($reportItem->outcome) {
            case \App\Entity\PayoutOutcome::Confirmed:
                $payout
                    ->setStatus(PayoutStatus::Confirmed)
                    ->setFailureKind(null)
                    ->setConfirmedAt($now)
                    ->setError(null);
                break;
            case \App\Entity\PayoutOutcome::Broadcast:
                $payout
                    ->setStatus(PayoutStatus::Broadcast)
                    ->setFailureKind(null)
                    ->setBroadcastAt($now)
                    ->setError(null);
                break;
            case \App\Entity\PayoutOutcome::Failed:
                $payout
                    ->setStatus(PayoutStatus::Failed)
                    ->setFailureKind($reportItem->failureKind);
                if ($reportItem->error !== null) {
                    $payout->setError($reportItem->error);
                }
                break;
        }

        $payout->touch($now);
    }

    private function markFailed(Payout $payout, \DateTimeImmutable $now, string $error): void
    {
        $payout
            ->setStatus(PayoutStatus::Failed)
            ->setFailureKind(PayoutFailureKind::Permanent)
            ->setError($error)
            ->touch($now);
        $this->entityManager->persist($payout);
    }
}
