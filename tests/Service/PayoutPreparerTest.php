<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Payout;
use App\Entity\PayoutFailureKind;
use App\Entity\PayoutStatus;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\Worker;
use App\Repository\PayoutRepository;
use App\Repository\TaskRepository;
use App\Service\PayoutBundleStorage;
use App\Service\PayoutPreparer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the online preparation step against the real repositories (so the
 * task-selection query and the unique payout per task are covered). The new
 * preparer never touches the TRON node.
 */
final class PayoutPreparerTest extends KernelTestCase
{
    private const ESCROW = 'TEsUWfRqtr6jmjHuxvrEMVzoSh5Mz52TzT';
    private const WORKER_ADDRESS = 'TUEZSdKsoDHQMeZwihtdoBiN46zxhGWYdH';
    private const REFUND_ADDRESS = 'TSeJkUh4Qv67VNFwY8LaAxERygNdy6NQZK';
    private const PRICE_SUN = 10_000_000;

    private EntityManagerInterface $em;
    private TaskRepository $tasks;
    private PayoutRepository $payouts;
    private string $payoutDir;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->tasks = $container->get(TaskRepository::class);
        $this->payouts = $container->get(PayoutRepository::class);
        $this->projectDir = (string) $container->getParameter('kernel.project_dir');
        $this->payoutDir = sys_get_temp_dir().'/payout-prepare-'.bin2hex(random_bytes(6));

        $this->em->clear();
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        $this->removeDirectory($this->payoutDir);

        parent::tearDown();
    }

    public function testPreparesAWorkerPayoutForAnAcceptedTask(): void
    {
        $worker = $this->createWorker();
        $task = $this->createTask(TaskStatus::Accepted, worker: $worker);
        $walletIndex = (int) $task->getWallet()?->getIndex();

        $result = $this->preparer()->prepare(new \DateTimeImmutable(), 'batch-1', 50);

        self::assertSame([], $result->failed);
        self::assertCount(1, $result->prepared);
        self::assertNotNull($result->intentPath);
        self::assertStringEndsWith('batch-1.intent.json', (string) $result->intentPath);

        $intent = $this->storage()->readIntent((string) $result->intentPath);
        self::assertCount(1, $intent->items);
        self::assertSame(self::WORKER_ADDRESS, $intent->items[0]->toAddress);
        self::assertSame('worker', $intent->items[0]->direction);
        self::assertSame(self::PRICE_SUN, $intent->items[0]->amountSun);
        self::assertSame(self::ESCROW, $intent->items[0]->fromAddress);
        self::assertSame($walletIndex, $intent->items[0]->walletIndex);
        self::assertTrue($intent->hasValidChecksum());

        $payout = $this->payouts->findOneByTask($task);
        self::assertNotNull($payout);
        self::assertSame(PayoutStatus::Prepared, $payout->getStatus());
        self::assertSame('batch-1', $payout->getBatchId());
        self::assertSame(self::ESCROW, $payout->getFromAddress());
        self::assertSame($walletIndex, $payout->getWalletIndex());
        self::assertNull($payout->getTxId());
        self::assertNotNull($payout->getPreparedAt());
    }

    public function testPreparesARefundForARejectedTask(): void
    {
        $task = $this->createTask(TaskStatus::Rejected, refundAddress: self::REFUND_ADDRESS);

        $result = $this->preparer()->prepare(new \DateTimeImmutable(), 'batch-2', 50);

        self::assertSame([], $result->failed);
        $payout = $this->payouts->findOneByTask($task);
        self::assertNotNull($payout);
        self::assertSame('refund', $payout->getDirection()?->value);
        self::assertSame(self::REFUND_ADDRESS, $payout->getToAddress());
    }

    public function testFailsWhenARejectedTaskHasNoRefundAddress(): void
    {
        $task = $this->createTask(TaskStatus::Rejected, refundAddress: null);

        $result = $this->preparer()->prepare(new \DateTimeImmutable(), 'batch-4', 50);

        self::assertCount(1, $result->failed);
        self::assertStringContainsString('no refund address', $result->failed[0]['error']);
        self::assertSame(PayoutStatus::Failed, $this->payouts->findOneByTask($task)?->getStatus());
        self::assertSame(PayoutFailureKind::Permanent, $this->payouts->findOneByTask($task)?->getFailureKind());
    }

    public function testFailsWhenAnAcceptedTaskHasNoExecutor(): void
    {
        $task = $this->createTask(TaskStatus::Accepted, worker: null);

        $result = $this->preparer()->prepare(new \DateTimeImmutable(), 'batch-5', 50);

        self::assertCount(1, $result->failed);
        self::assertStringContainsString('no executor', $result->failed[0]['error']);
    }

    public function testFailsWhenTheTaskHasNoPositiveAmount(): void
    {
        $worker = $this->createWorker();
        $task = $this->createTask(TaskStatus::Accepted, worker: $worker, priceSun: 0);

        $result = $this->preparer()->prepare(new \DateTimeImmutable(), 'batch-6', 50);

        self::assertCount(1, $result->failed);
        self::assertStringContainsString('no positive amount', $result->failed[0]['error']);
    }

    public function testSkipsATaskWhosePayoutWasAlreadySettled(): void
    {
        $worker = $this->createWorker();
        $task = $this->createTask(TaskStatus::Accepted, worker: $worker);

        $this->preparer()->prepare(new \DateTimeImmutable(), 'batch-7', 50);

        $payout = $this->payouts->findOneByTask($task);
        self::assertNotNull($payout);
        $payout->setStatus(PayoutStatus::Broadcast)->setBroadcastAt(new \DateTimeImmutable());
        $this->em->flush();

        $result = $this->preparer()->prepare(new \DateTimeImmutable(), 'batch-7', 50);

        // A broadcast payout is excluded by the selection query, so the task is
        // not even considered: this is what makes the daily run idempotent.
        self::assertSame([], $result->prepared);
        self::assertSame([], $result->failed);
        self::assertSame([], $result->skipped);
        self::assertNull($result->intentPath);
    }

    public function testDoesNotRebuildAPreparedPayoutThatIsStillPending(): void
    {
        $worker = $this->createWorker();
        $task = $this->createTask(TaskStatus::Accepted, worker: $worker);

        $preparer = $this->preparer();
        $preparer->prepare(new \DateTimeImmutable(), 'batch-8', 50);

        $result = $preparer->prepare(new \DateTimeImmutable(), 'batch-8', 50);

        self::assertSame([], $result->prepared);
        self::assertNull($result->intentPath);
        self::assertSame('waiting for the settlement machine', $result->skipped[0]['reason']);
    }

    public function testRebuildsARetryableFailedPayout(): void
    {
        $worker = $this->createWorker();
        $task = $this->createTask(TaskStatus::Accepted, worker: $worker);
        $this->createFailedPayout($task, PayoutFailureKind::Retryable);

        $result = $this->preparer()->prepare(new \DateTimeImmutable(), 'batch-9', 50);

        self::assertCount(1, $result->prepared);
        self::assertSame(PayoutStatus::Prepared, $this->payouts->findOneByTask($task)?->getStatus());
    }

    public function testSkipsAPermanentFailedPayout(): void
    {
        $worker = $this->createWorker();
        $task = $this->createTask(TaskStatus::Accepted, worker: $worker);
        $this->createFailedPayout($task, PayoutFailureKind::Permanent);

        $result = $this->preparer()->prepare(new \DateTimeImmutable(), 'batch-10', 50);

        // The selection query excludes permanent failures outright.
        self::assertSame([], $result->prepared);
        self::assertSame([], $result->failed);
        self::assertSame([], $result->skipped);
    }

    public function testDryRunWritesNothing(): void
    {
        $worker = $this->createWorker();
        $task = $this->createTask(TaskStatus::Accepted, worker: $worker);

        $result = $this->preparer()->prepare(new \DateTimeImmutable(), 'batch-11', 50, true);

        self::assertCount(1, $result->prepared);
        self::assertNull($result->intentPath);
        self::assertNull($this->payouts->findOneByTask($task));
        self::assertCount(0, glob($this->payoutDir.'/*') ?: []);
    }

    public function testRefusesToRunWhilePayoutsAreDisabled(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PAYOUT_ENABLED/');

        $this->preparer(enabled: false)->prepare(new \DateTimeImmutable());
    }

    private function preparer(bool $enabled = true): PayoutPreparer
    {
        return new PayoutPreparer(
            tasks: $this->tasks,
            payouts: $this->payouts,
            storage: $this->storage(),
            entityManager: $this->em,
            network: 'nile',
            batchSize: 50,
            enabled: $enabled,
        );
    }

    private function storage(): PayoutBundleStorage
    {
        return new PayoutBundleStorage($this->payoutDir, $this->projectDir);
    }

    private function createFailedPayout(Task $task, PayoutFailureKind $kind): Payout
    {
        $payout = new Payout(task: $task);
        $payout
            ->setDirection(\App\Entity\PayoutDirection::Worker)
            ->setFromAddress(self::ESCROW)
            ->setAmountSun(self::PRICE_SUN)
            ->setWalletIndex(0)
            ->setStatus(PayoutStatus::Failed)
            ->setFailureKind($kind)
            ->setError('earlier failure');
        $this->em->persist($payout);
        $this->em->flush();

        return $payout;
    }

    private function createWorker(): Worker
    {
        $worker = new Worker();
        $worker->setTronAddress(self::WORKER_ADDRESS);
        $worker->setPassword('not-a-real-hash');
        $this->em->persist($worker);
        $this->em->flush();

        return $worker;
    }

    private function createTask(
        TaskStatus $status,
        int $priceSun = self::PRICE_SUN,
        ?string $refundAddress = self::REFUND_ADDRESS,
        ?Worker $worker = null,
    ): Task {
        $task = new Task();
        $task->setStatement('Payout preparation test task.');
        $task->setPassword('task-password');
        $task->setStatus($status);
        $task->setPriceSun($priceSun);
        $task->setRefundAddress($refundAddress);
        $task->setAssignedTo($worker);
        $task->getWallet()?->setAddress(self::ESCROW);

        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }
}
