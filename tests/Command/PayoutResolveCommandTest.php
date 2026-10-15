<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PayoutResolveCommand;
use App\Entity\PayoutStatus;
use App\Repository\PayoutRepository;
use App\Repository\TaskRepository;
use App\Tests\Helper\CreatesPayouts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Manual failure handling: a payout that cannot be settled is abandoned so the
 * task may be prepared again.
 */
final class PayoutResolveCommandTest extends KernelTestCase
{
    use CreatesPayouts;

    private EntityManagerInterface $em;
    private TaskRepository $tasks;
    private PayoutRepository $payouts;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->tasks = $container->get(TaskRepository::class);
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

    public function testAbandonsAFailedPayout(): void
    {
        $task = $this->createPayoutTask($this->em);
        $payout = $this->createPayoutRow($this->em, $task, PayoutStatus::Failed);

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'task' => (string) $task->getId(),
            '--abandon' => true,
            '--reason' => 'escrow was topped up too late',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(PayoutStatus::Abandoned, $payout->getStatus());
        self::assertNotNull($payout->getAbandonedAt());
        self::assertSame('escrow was topped up too late', $payout->getError());
    }

    public function testAbandonsAPreparedPayoutWhoseIntentWasLost(): void
    {
        $task = $this->createPayoutTask($this->em);
        $payout = $this->createPayoutRow($this->em, $task, PayoutStatus::Prepared);

        $tester = $this->tester();
        $exitCode = $tester->execute(['task' => (string) $task->getId(), '--abandon' => true]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(PayoutStatus::Abandoned, $payout->getStatus());
    }

    public function testRefusesToAbandonAConfirmedPayout(): void
    {
        $task = $this->createPayoutTask($this->em);
        $payout = $this->createPayoutRow($this->em, $task, PayoutStatus::Confirmed, confirmedAt: new \DateTimeImmutable());

        $tester = $this->tester();
        $exitCode = $tester->execute(['task' => (string) $task->getId(), '--abandon' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(PayoutStatus::Confirmed, $payout->getStatus());
    }

    public function testRequiresTheAbandonOption(): void
    {
        $task = $this->createPayoutTask($this->em);
        $this->createPayoutRow($this->em, $task, PayoutStatus::Failed);

        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute(['task' => (string) $task->getId()]));
    }

    public function testRejectsAnUnknownOrMalformedTaskId(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['task' => 'not-a-uuid', '--abandon' => true]));
        self::assertSame(
            Command::FAILURE,
            $tester->execute(['task' => '6f8a0d2e-1c3b-4a5d-8e7f-9a0b1c2d3e4f', '--abandon' => true]),
        );
        self::assertStringContainsString('was not found', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new PayoutResolveCommand($this->tasks, $this->payouts, $this->em));
    }
}
