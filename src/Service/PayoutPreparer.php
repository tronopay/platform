<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Payout;
use App\Entity\PayoutDirection;
use App\Entity\PayoutFailureKind;
use App\Entity\PayoutStatus;
use App\Entity\Task;
use App\Repository\PayoutRepository;
use App\Repository\TaskRepository;
use App\ValueObject\PayoutIntentBundle;
use App\ValueObject\PayoutIntentItem;
use App\ValueObject\PayoutPrepareResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns finished tasks (`accepted` / `rejected`) into escrow payout intents and
 * writes them to one `<batchId>.intent.json` file for the settlement machine.
 *
 * This step is strictly business logic: it decides what should be paid and to
 * whom, and writes a plain intent. It never talks to the TRON node, never
 * builds a transaction and never touches a key — the machine with the mnemonic
 * does all of that when it settles the batch.
 */
final readonly class PayoutPreparer
{
    public function __construct(
        private TaskRepository $tasks,
        private PayoutRepository $payouts,
        private PayoutBundleStorage $storage,
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(TRON_NETWORK)%')]
        private string $network = 'mainnet',
        #[Autowire('%payout_batch_size%')]
        private int $batchSize = 200,
        #[Autowire('%env(bool:PAYOUT_ENABLED)%')]
        private bool $enabled = false,
    ) {
    }

    public function prepare(
        \DateTimeImmutable $now,
        ?string $batchId = null,
        ?int $limit = null,
        bool $dryRun = false,
    ): PayoutPrepareResult {
        if (!$this->enabled) {
            throw new \RuntimeException('Escrow payouts are disabled: set PAYOUT_ENABLED=1 to prepare a batch.');
        }

        $batchId ??= $now->format('Y-m-d');
        $limit = max(1, $limit ?? $this->batchSize);

        $prepared = [];
        $failed = [];
        $skipped = [];
        $intentItems = [];
        $lastId = null;

        while (true) {
            $tasks = $this->tasks->findTasksForPayoutAfter($lastId, $limit);
            if ([] === $tasks) {
                break;
            }

            foreach ($tasks as $task) {
                $lastId = $task->getId();

                $result = $this->examine($task, $now, $batchId, $dryRun);
                switch ($result['outcome']) {
                    case 'prepared':
                        $prepared[] = $result['info'];
                        break;
                    case 'failed':
                        $failed[] = $result['info'];
                        break;
                    default:
                        $skipped[] = $result['info'];
                }

                if (isset($result['item'])) {
                    $intentItems[] = $result['item'];
                }

                if (\count($prepared) >= $limit) {
                    break 2;
                }
            }
        }

        $intentPath = null;
        if (!$dryRun && [] !== $intentItems) {
            $intentPath = $this->storage->writeIntent(new PayoutIntentBundle(
                network: $this->network,
                batchId: $batchId,
                createdAt: $now,
                items: $intentItems,
            ));
        }

        if (!$dryRun) {
            try {
                $this->entityManager->flush();
            } catch (\Throwable $exception) {
                if ($intentPath !== null) {
                    @unlink($intentPath);
                }

                throw $exception;
            }
        }

        return new PayoutPrepareResult($batchId, $prepared, $failed, $skipped, $intentPath, $dryRun);
    }

    /**
     * @return array{outcome: 'prepared'|'failed'|'skipped', info: array<string, mixed>, item?: PayoutIntentItem}
     */
    private function examine(Task $task, \DateTimeImmutable $now, string $batchId, bool $dryRun): array
    {
        $taskId = (string) $task->getId();
        $direction = PayoutDirection::forStatus($task->getStatus());
        if (null === $direction) {
            return $this->skipped($taskId, sprintf('task status "%s" is not settled', $task->getStatus()->value));
        }

        $payout = $this->payouts->findOneByTask($task);
        if (null !== $payout) {
            if ($payout->getStatus()->isSettled()) {
                return $this->skipped($taskId, 'already settled');
            }

            if (!$payout->canBeRebuilt($now)) {
                return $this->skipped($taskId, 'waiting for the settlement machine');
            }
        }

        $amountSun = (int) $task->getPriceSun();
        if ($amountSun <= 0) {
            return $this->failure($task, $payout, $now, $dryRun, $taskId, 'the task has no positive amount');
        }

        $wallet = $task->getWallet();
        $fromAddress = $wallet?->getAddress();
        $walletIndex = $wallet?->getIndex();
        if (null === $fromAddress || null === $walletIndex) {
            return $this->failure($task, $payout, $now, $dryRun, $taskId, 'the escrow wallet address is not derived yet');
        }

        $toAddress = PayoutDirection::Worker === $direction
            ? $task->getAssignedTo()?->getTronAddress()
            : $task->getRefundAddress();
        if (null === $toAddress || '' === trim($toAddress)) {
            return $this->failure($task, $payout, $now, $dryRun, $taskId, PayoutDirection::Worker === $direction
                ? 'the accepted task has no executor'
                : 'the rejected task has no refund address');
        }

        if ($toAddress === $fromAddress) {
            return $this->failure($task, $payout, $now, $dryRun, $taskId, 'the recipient equals the escrow wallet');
        }

        $info = [
            'taskId' => $taskId,
            'direction' => $direction->value,
            'amountSun' => $amountSun,
            'toAddress' => $toAddress,
        ];

        if ($dryRun) {
            return ['outcome' => 'prepared', 'info' => $info];
        }

        $payout ??= new Payout(task: $task);
        $payout
            ->setTask($task)
            ->setDirection($direction)
            ->setFromAddress($fromAddress)
            ->setToAddress($toAddress)
            ->setAmountSun($amountSun)
            ->setWalletIndex($walletIndex)
            ->setStatus(PayoutStatus::Prepared)
            ->setTxId(null)
            ->setFailureKind(null)
            ->setFeeSun(null)
            ->setBlockNumber(null)
            ->setBatchId($batchId)
            ->setError(null)
            ->setPreparedAt($now)
            ->setBroadcastAt(null)
            ->setConfirmedAt(null)
            ->setAbandonedAt(null)
            ->touch($now);
        $this->entityManager->persist($payout);

        return [
            'outcome' => 'prepared',
            'info' => $info,
            'item' => new PayoutIntentItem(
                payoutId: (string) $payout->getId(),
                taskId: $taskId,
                direction: $direction->value,
                walletIndex: $walletIndex,
                fromAddress: $fromAddress,
                toAddress: $toAddress,
                amountSun: $amountSun,
            ),
        ];
    }

    private function markFailed(?Payout $payout, Task $task, \DateTimeImmutable $now, string $error, PayoutFailureKind $kind): void
    {
        $payout ??= new Payout(task: $task);
        $payout
            ->setTask($task)
            ->setStatus(PayoutStatus::Failed)
            ->setTxId(null)
            ->setFailureKind($kind)
            ->setError($error)
            ->touch($now);
        $this->entityManager->persist($payout);
    }

    /**
     * @return array{outcome: 'failed', info: array{taskId: string, error: string}}
     */
    private function failure(
        Task $task,
        ?Payout $payout,
        \DateTimeImmutable $now,
        bool $dryRun,
        string $taskId,
        string $error,
    ): array {
        if (!$dryRun) {
            $this->markFailed($payout, $task, $now, $error, PayoutFailureKind::Permanent);
        }

        return ['outcome' => 'failed', 'info' => ['taskId' => $taskId, 'error' => $error]];
    }

    /**
     * @return array{outcome: 'skipped', info: array{taskId: string, reason: string}}
     */
    private function skipped(string $taskId, string $reason): array
    {
        return ['outcome' => 'skipped', 'info' => ['taskId' => $taskId, 'reason' => $reason]];
    }
}
