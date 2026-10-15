<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PayoutBundleStorage;
use App\Service\PayoutReportApplier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:payout:apply-report',
    description: 'Fold a settlement report back into the payout rows.',
)]
final class PayoutApplyReportCommand extends Command
{
    public function __construct(
        private readonly PayoutReportApplier $applier,
        private readonly PayoutBundleStorage $storage,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('intent', null, InputOption::VALUE_REQUIRED, 'Path to the intent file the report answers')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Path to the report file written by app:payout:settle')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Verify the report without writing to the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $intentPath = $this->requiredOption($input, $io, 'intent');
        $reportPath = $this->requiredOption($input, $io, 'report');
        if (null === $intentPath || null === $reportPath) {
            return Command::INVALID;
        }

        try {
            $intent = $this->storage->readIntent($intentPath);
            $report = $this->storage->readReport($reportPath);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($intent->batchId !== $report->batchId || $intent->network !== $report->network) {
            $io->error('The intent and the report do not belong to the same batch/network.');

            return Command::FAILURE;
        }

        $lock = $this->lockFactory->createLockFromKey(new Key('app.payout-apply-report'));
        if (!$lock->acquire()) {
            $io->warning('Another report is already being applied. Skipping.');

            return Command::SUCCESS;
        }

        try {
            try {
                $result = $this->applier->apply(
                    $intent,
                    $report,
                    new \DateTimeImmutable(),
                    (bool) $input->getOption('dry-run'),
                );
            } catch (\Throwable $exception) {
                $io->error($exception->getMessage());

                return Command::FAILURE;
            }

            if ([] !== $result->applied) {
                $io->table(
                    ['task', 'payout', 'status'],
                    array_map(
                        static fn (array $row): array => [$row['taskId'], $row['payoutId'], $row['status']],
                        $result->applied,
                    ),
                );
            }

            if ([] !== $result->skipped) {
                $io->table(
                    ['task', 'payout', 'skipped because'],
                    array_map(
                        static fn (array $row): array => [$row['taskId'], $row['payoutId'], $row['reason']],
                        $result->skipped,
                    ),
                );
            }

            if ([] !== $result->failed) {
                $io->table(
                    ['task', 'payout', 'error'],
                    array_map(
                        static fn (array $row): array => [$row['taskId'], $row['payoutId'], $row['error']],
                        $result->failed,
                    ),
                );
            }

            if ($result->isEmpty()) {
                $io->success('Nothing to apply.');

                return Command::SUCCESS;
            }

            $io->success(sprintf(
                '%d payout(s) updated from batch "%s"%s.',
                \count($result->applied),
                $result->batchId,
                $result->dryRun ? ' (dry run)' : '',
            ));

            return [] === $result->failed ? Command::SUCCESS : Command::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function requiredOption(InputInterface $input, SymfonyStyle $io, string $name): ?string
    {
        $value = $input->getOption($name);
        if (null === $value || '' === trim((string) $value)) {
            $io->error(sprintf('--%s is required.', $name));

            return null;
        }

        return (string) $value;
    }
}
