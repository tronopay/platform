<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PayoutApplyReportCommand;
use App\Command\PayoutPrepareCommand;
use App\Command\PayoutSettleCommand;
use App\Entity\PayoutStatus;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\Worker;
use App\Repository\PayoutRepository;
use App\Repository\TaskRepository;
use App\Service\HdWalletMnemonicSigner;
use App\Service\OfflineTransferSigner;
use App\Service\PayoutBundleStorage;
use App\Service\PayoutPreparer;
use App\Service\PayoutReportApplier;
use App\Service\PayoutSettler;
use App\Service\TronGatewayInterface;
use App\Tests\Helper\TronTransactionFixture;
use App\ValueObject\PayoutReceipt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Drives the documented operator workflow end to end through the console
 * commands: prepare (online) -> settle (offline, key-holding machine) ->
 * apply-report (online), with only the TRON node stubbed out.
 */
final class PayoutWorkflowCommandTest extends KernelTestCase
{
    private const MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
    private const RECIPIENT = 'THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY';
    private const AMOUNT_SUN = 25_000_000;

    private EntityManagerInterface $em;
    private TaskRepository $tasks;
    private PayoutRepository $payouts;
    private PayoutBundleStorage $storage;
    private PayoutReportApplier $applier;
    private string $payoutDir;
    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->tasks = $container->get(TaskRepository::class);
        $this->payouts = $container->get(PayoutRepository::class);
        $this->payoutDir = sys_get_temp_dir().'/payout-workflow-'.bin2hex(random_bytes(6));
        $this->storage = new PayoutBundleStorage($this->payoutDir, (string) $container->getParameter('kernel.project_dir'));
        $this->applier = new PayoutReportApplier($this->payouts, $this->em);
        $this->lockFactory = new LockFactory(new InMemoryStore());

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

    public function testPrepareSettleApplyReportRoundTrip(): void
    {
        $task = $this->createAcceptedTask();
        $escrow = (string) $task->getWallet()?->getAddress();

        // 1. Online: write the intent file.
        $prepare = new CommandTester(new PayoutPrepareCommand($this->preparer(), $this->lockFactory));
        $exitCode = $prepare->execute(['--date' => 'workflow-batch']);

        self::assertSame(Command::SUCCESS, $exitCode, $prepare->getDisplay());
        self::assertStringContainsString('1 payout(s) prepared', $prepare->getDisplay());

        $intentPath = $this->payoutDir.'/workflow-batch.intent.json';
        self::assertFileExists($intentPath);
        self::assertSame(PayoutStatus::Prepared, $this->payouts->findOneByTask($task)?->getStatus());

        // 2. Offline: the key-holding machine settles the intent and writes a report.
        $settle = new CommandTester(new PayoutSettleCommand(
            $this->storage,
            $this->settler($this->gateway($escrow)),
            mnemonicFromEnv: '',
            passphraseFromEnv: '',
        ));
        $mnemonicFile = $this->writeFile('mnemonic.txt', self::MNEMONIC."\n");
        $reportPath = $this->payoutDir.'/workflow-batch.report.json';
        $exitCode = $settle->execute([
            '--in' => $intentPath,
            '--out' => $reportPath,
            '--mnemonic-file' => $mnemonicFile,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $settle->getDisplay());
        self::assertFileExists($reportPath);

        $report = $this->storage->readReport($reportPath);
        self::assertCount(1, $report->items);
        self::assertSame('confirmed', $report->items[0]->outcome->value);
        $txId = $report->items[0]->txId;

        // 3. Online: fold the report back into the payout row.
        $apply = new CommandTester(new PayoutApplyReportCommand($this->applier, $this->storage, $this->lockFactory));
        $exitCode = $apply->execute([
            '--intent' => $intentPath,
            '--report' => $reportPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $apply->getDisplay());
        self::assertStringContainsString('1 payout(s) updated', $apply->getDisplay());

        $payout = $this->payouts->findOneByTask($task);
        self::assertNotNull($payout);
        self::assertSame(PayoutStatus::Confirmed, $payout->getStatus());
        self::assertSame($txId, $payout->getTxId());
        self::assertSame(100_000, $payout->getFeeSun());
    }

    public function testDryRunWritesNothing(): void
    {
        $task = $this->createAcceptedTask();

        $prepare = new CommandTester(new PayoutPrepareCommand($this->preparer(), $this->lockFactory));
        $exitCode = $prepare->execute(['--date' => 'workflow-batch', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode, $prepare->getDisplay());
        self::assertStringContainsString('dry run', $prepare->getDisplay());
        self::assertFileDoesNotExist($this->payoutDir.'/workflow-batch.intent.json');
        self::assertNull($this->payouts->findOneByTask($task));
    }

    public function testApplyReportRefusesAMismatchedIntentAndReport(): void
    {
        $task = $this->createAcceptedTask();
        $escrow = (string) $task->getWallet()?->getAddress();

        $prepare = new CommandTester(new PayoutPrepareCommand($this->preparer(), $this->lockFactory));
        $prepare->execute(['--date' => 'workflow-batch']);

        $intentPath = $this->payoutDir.'/workflow-batch.intent.json';
        $settle = new CommandTester(new PayoutSettleCommand(
            $this->storage,
            $this->settler($this->gateway($escrow)),
            mnemonicFromEnv: '',
            passphraseFromEnv: '',
        ));
        $settle->execute([
            '--in' => $intentPath,
            '--out' => $this->payoutDir.'/workflow-batch.report.json',
            '--mnemonic-file' => $this->writeFile('mnemonic.txt', self::MNEMONIC),
        ]);

        // Point apply-report at a report batch id that does not match the intent.
        $wrong = $this->payoutDir.'/wrong.report.json';
        file_put_contents($wrong, (string) file_get_contents($this->payoutDir.'/workflow-batch.report.json'));
        file_put_contents($wrong, str_replace(
            '"batchId": "workflow-batch"',
            '"batchId": "other-batch"',
            (string) file_get_contents($wrong),
        ));

        $apply = new CommandTester(new PayoutApplyReportCommand($this->applier, $this->storage, $this->lockFactory));
        $exitCode = $apply->execute(['--intent' => $intentPath, '--report' => $wrong]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('do not belong to the same batch', $apply->getDisplay());
    }

    private function preparer(): PayoutPreparer
    {
        return new PayoutPreparer(
            tasks: $this->tasks,
            payouts: $this->payouts,
            storage: $this->storage,
            entityManager: $this->em,
            network: 'nile',
            batchSize: 50,
            enabled: true,
        );
    }

    private function settler(TronGatewayInterface $gateway): PayoutSettler
    {
        return new PayoutSettler(
            hdWallet: new HdWalletMnemonicSigner(),
            offlineSigner: new OfflineTransferSigner(),
            gateway: $gateway,
            storage: $this->storage,
            network: 'nile',
            ttl: '30 minutes',
            feeMarginPercent: 20,
        );
    }

    private function gateway(string $escrow): TronGatewayInterface
    {
        $gateway = $this->createStub(TronGatewayInterface::class);
        $gateway->method('buildTransfer')->willReturnCallback(
            fn (string $from, string $to, int $amount, \DateTimeImmutable $expiresAt) => TronTransactionFixture::unsignedTransfer(
                $from,
                $to,
                $amount,
                (int) $expiresAt->format('Uv'),
            ),
        );
        $gateway->method('estimateFeeSun')->willReturn(100_000);
        $gateway->method('balanceSun')->willReturn(self::AMOUNT_SUN + 1_000_000);
        $gateway->method('hasTransaction')->willReturn(false);
        $gateway->method('broadcastSigned')->willReturn(str_repeat('ab', 32));
        $gateway->method('receipt')->willReturn(new PayoutReceipt(str_repeat('ab', 32), true, 654321, 100_000, 'SUCCESS'));
        $gateway->method('findTransfer')->willReturn(['from' => $escrow, 'to' => self::RECIPIENT, 'amountSun' => self::AMOUNT_SUN]);

        return $gateway;
    }

    private function createAcceptedTask(): Task
    {
        $worker = new Worker();
        $worker->setTronAddress(self::RECIPIENT);
        $worker->setPassword('not-a-real-hash');
        $this->em->persist($worker);
        $this->em->flush();

        $task = new Task();
        $task->setStatement('Workflow test task.');
        $task->setPassword('task-password');
        $task->setStatus(TaskStatus::Accepted);
        $task->setPriceSun(self::AMOUNT_SUN);
        $task->setAssignedTo($worker);
        $this->em->persist($task);
        $this->em->flush();

        // The wallet primary key is the HD derivation index; derive the escrow
        // address from it exactly as the watch-only side does in production.
        $index = (int) $task->getWallet()?->getIndex();
        $escrow = (new HdWalletMnemonicSigner())->signerFor(self::MNEMONIC, $index)->address()->toBase58();
        $task->getWallet()?->setAddress($escrow);
        $this->em->flush();

        return $task;
    }

    private function writeFile(string $name, string $contents): string
    {
        if (!is_dir($this->payoutDir)) {
            @mkdir($this->payoutDir, 0700, true);
        }
        $path = $this->payoutDir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
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
