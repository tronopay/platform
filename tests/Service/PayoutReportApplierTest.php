<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Payout;
use App\Entity\PayoutDirection;
use App\Entity\PayoutFailureKind;
use App\Entity\PayoutOutcome;
use App\Entity\PayoutStatus;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Repository\PayoutRepository;
use App\Service\PayoutReportApplier;
use App\ValueObject\PayoutIntentBundle;
use App\ValueObject\PayoutIntentItem;
use App\ValueObject\PayoutReportBundle;
use App\ValueObject\PayoutReportItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises folding a settlement report back into the payout rows, including
 * idempotency and the anti-tamper mismatch rules.
 */
final class PayoutReportApplierTest extends KernelTestCase
{
    private const ESCROW = 'TEsUWfRqtr6jmjHuxvrEMVzoSh5Mz52TzT';
    private const RECIPIENT = 'THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY';
    private const AMOUNT_SUN = 10_000_000;

    private EntityManagerInterface $em;
    private PayoutRepository $payouts;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->payouts = $container->get(PayoutRepository::class);

        $this->em->clear();
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }

        parent::tearDown();
    }

    public function testAppliesAConfirmedReport(): void
    {
        [$task, $payout] = $this->fixture();

        $result = $this->applier()->apply(
            $this->intent($payout),
            $this->report($payout, PayoutOutcome::Confirmed, txId: str_repeat('ab', 32), feeSun: 123, blockNumber: 654321),
            new \DateTimeImmutable(),
        );

        self::assertCount(1, $result->applied);
        self::assertSame('confirmed', $result->applied[0]['status']);
        $this->em->clear();

        $payout = $this->payouts->find($payout->getId());
        self::assertSame(PayoutStatus::Confirmed, $payout->getStatus());
        self::assertSame(str_repeat('ab', 32), $payout->getTxId());
        self::assertSame(123, $payout->getFeeSun());
        self::assertSame(654321, $payout->getBlockNumber());
        self::assertNotNull($payout->getConfirmedAt());
    }

    public function testAppliesABroadcastReport(): void
    {
        [, $payout] = $this->fixture();

        $this->applier()->apply(
            $this->intent($payout),
            $this->report($payout, PayoutOutcome::Broadcast, txId: str_repeat('cd', 32)),
            new \DateTimeImmutable(),
        );

        $this->em->clear();
        $payout = $this->payouts->find($payout->getId());
        self::assertSame(PayoutStatus::Broadcast, $payout->getStatus());
        self::assertNotNull($payout->getBroadcastAt());
        self::assertNull($payout->getConfirmedAt());
    }

    public function testAppliesAFailedRetryableReport(): void
    {
        [, $payout] = $this->fixture();

        $this->applier()->apply(
            $this->intent($payout),
            $this->report($payout, PayoutOutcome::Failed, failureKind: PayoutFailureKind::Retryable, error: 'insufficient funds'),
            new \DateTimeImmutable(),
        );

        $this->em->clear();
        $payout = $this->payouts->find($payout->getId());
        self::assertSame(PayoutStatus::Failed, $payout->getStatus());
        self::assertSame(PayoutFailureKind::Retryable, $payout->getFailureKind());
        self::assertSame('insufficient funds', $payout->getError());
    }

    public function testIsIdempotentForAnAlreadySettledPayout(): void
    {
        [, $payout] = $this->fixture();
        $applier = $this->applier();

        $applier->apply(
            $this->intent($payout),
            $this->report($payout, PayoutOutcome::Confirmed, txId: str_repeat('ab', 32)),
            new \DateTimeImmutable(),
        );

        $result = $applier->apply(
            $this->intent($payout),
            $this->report($payout, PayoutOutcome::Confirmed, txId: str_repeat('ab', 32)),
            new \DateTimeImmutable(),
        );

        self::assertSame([], $result->applied);
        self::assertCount(1, $result->skipped);
        self::assertSame('already settled', $result->skipped[0]['reason']);
    }

    public function testATamperedReportForcesAPermanentFailure(): void
    {
        [, $payout] = $this->fixture();

        $result = $this->applier()->apply(
            $this->intent($payout, amountSun: self::AMOUNT_SUN + 1),
            $this->report($payout, PayoutOutcome::Confirmed, txId: str_repeat('ab', 32)),
            new \DateTimeImmutable(),
        );

        self::assertSame([], $result->applied);
        self::assertCount(1, $result->failed);
        self::assertStringContainsString('amount does not match', $result->failed[0]['error']);

        $this->em->clear();
        $payout = $this->payouts->find($payout->getId());
        self::assertSame(PayoutStatus::Failed, $payout->getStatus());
        self::assertSame(PayoutFailureKind::Permanent, $payout->getFailureKind());
    }

    public function testAReportItemNotInTheIntentIsRejected(): void
    {
        [, $payout] = $this->fixture();

        $result = $this->applier()->apply(
            $this->intent($payout, items: []),
            $this->report($payout, PayoutOutcome::Confirmed, txId: str_repeat('ab', 32)),
            new \DateTimeImmutable(),
        );

        self::assertCount(1, $result->failed);
        self::assertStringContainsString('not part of the intent', $result->failed[0]['error']);
    }

    public function testAReportForAnUnknownPayoutIsReportedAsFailed(): void
    {
        [, $payout] = $this->fixture();

        $report = new PayoutReportBundle(
            network: 'nile',
            batchId: 'batch-1',
            createdAt: new \DateTimeImmutable(),
            settledAt: new \DateTimeImmutable(),
            items: [new PayoutReportItem('t-unknown', '5f6f8f6f-4e4f-4c4f-9f9f-0a0b0c0d0e0f', PayoutOutcome::Confirmed, str_repeat('ab', 32))],
        );

        $result = $this->applier()->apply($this->intent($payout), $report, new \DateTimeImmutable());

        self::assertCount(1, $result->failed);
        self::assertStringContainsString('no matching payout row', $result->failed[0]['error']);
    }

    private function applier(): PayoutReportApplier
    {
        return new PayoutReportApplier($this->payouts, $this->em);
    }

    /**
     * @return array{0: Task, 1: Payout}
     */
    private function fixture(): array
    {
        $task = new Task();
        $task->setStatement('Report applier test task.');
        $task->setPassword('task-password');
        $task->setStatus(TaskStatus::Accepted);
        $task->setPriceSun(self::AMOUNT_SUN);
        $task->getWallet()?->setAddress(self::ESCROW);
        $this->em->persist($task);
        $this->em->flush();

        $payout = new Payout(task: $task);
        $payout
            ->setDirection(PayoutDirection::Worker)
            ->setFromAddress(self::ESCROW)
            ->setToAddress(self::RECIPIENT)
            ->setAmountSun(self::AMOUNT_SUN)
            ->setWalletIndex(0)
            ->setStatus(PayoutStatus::Prepared)
            ->setPreparedAt(new \DateTimeImmutable());
        $this->em->persist($payout);
        $this->em->flush();

        return [$task, $payout];
    }

    /**
     * @param list<PayoutIntentItem>|null $items null means "build the default single item"
     */
    private function intent(Payout $payout, ?array $items = null, int $amountSun = self::AMOUNT_SUN): PayoutIntentBundle
    {
        $items = null !== $items ? $items : [new PayoutIntentItem(
            payoutId: (string) $payout->getId(),
            taskId: (string) $payout->getTask()?->getId(),
            direction: 'worker',
            walletIndex: 0,
            fromAddress: self::ESCROW,
            toAddress: self::RECIPIENT,
            amountSun: $amountSun,
        )];

        return new PayoutIntentBundle('nile', 'batch-1', new \DateTimeImmutable(), $items);
    }

    private function report(
        Payout $payout,
        PayoutOutcome $outcome,
        ?string $txId = null,
        ?int $feeSun = null,
        ?int $blockNumber = null,
        ?PayoutFailureKind $failureKind = null,
        ?string $error = null,
    ): PayoutReportBundle {
        return new PayoutReportBundle(
            network: 'nile',
            batchId: 'batch-1',
            createdAt: new \DateTimeImmutable(),
            settledAt: new \DateTimeImmutable(),
            items: [new PayoutReportItem(
                taskId: (string) $payout->getTask()?->getId(),
                payoutId: (string) $payout->getId(),
                outcome: $outcome,
                txId: $txId,
                feeSun: $feeSun,
                blockNumber: $blockNumber,
                failureKind: $failureKind,
                error: $error,
            )],
        );
    }
}
