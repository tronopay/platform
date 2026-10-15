<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PayoutStatusCommand;
use App\Entity\PayoutFailureKind;
use App\Entity\PayoutStatus;
use App\Repository\PayoutRepository;
use App\Tests\Helper\CreatesPayouts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PayoutStatusCommandTest extends KernelTestCase
{
    use CreatesPayouts;

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

    public function testFlagsOnlyFailedPayoutsAsNeedingAttention(): void
    {
        $failed = $this->createPayoutTask($this->em);
        $this->createPayoutRow($this->em, $failed, PayoutStatus::Failed, PayoutFailureKind::Retryable);

        $prepared = $this->createPayoutTask($this->em);
        $this->createPayoutRow($this->em, $prepared, PayoutStatus::Prepared);

        $broadcast = $this->createPayoutTask($this->em);
        $this->createPayoutRow($this->em, $broadcast, PayoutStatus::Broadcast, broadcastAt: new \DateTimeImmutable());

        $confirmed = $this->createPayoutTask($this->em);
        $this->createPayoutRow($this->em, $confirmed, PayoutStatus::Confirmed, confirmedAt: new \DateTimeImmutable());

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('4 payout(s) listed, 1 need attention.', $display);
        self::assertStringContainsString((string) $failed->getId(), $display);
        self::assertStringContainsString((string) $prepared->getId(), $display);
        self::assertStringContainsString((string) $broadcast->getId(), $display);
        self::assertStringContainsString((string) $confirmed->getId(), $display);
    }

    public function testFiltersByStatusAndBatch(): void
    {
        $failed = $this->createPayoutTask($this->em);
        $this->createPayoutRow($this->em, $failed, PayoutStatus::Failed, PayoutFailureKind::Permanent, batchId: 'batch-a');

        $prepared = $this->createPayoutTask($this->em);
        $this->createPayoutRow($this->em, $prepared, PayoutStatus::Prepared, batchId: 'batch-b');

        $tester = $this->tester();
        $tester->execute(['--status' => 'prepared']);

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('1 payout(s) listed', $display);
        self::assertStringContainsString((string) $prepared->getId(), $display);
        self::assertStringNotContainsString((string) $failed->getId(), $display);

        $tester->execute(['--batch' => 'batch-a']);

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('1 payout(s) listed', $display);
        self::assertStringContainsString((string) $failed->getId(), $display);
        self::assertStringNotContainsString((string) $prepared->getId(), $display);
    }

    public function testReportsWhenThereIsNothingToShow(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No payouts found', $tester->getDisplay());
    }

    public function testRejectsAnUnknownStatus(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--status' => 'paid']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Unknown status', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new PayoutStatusCommand($this->payouts));
    }
}
