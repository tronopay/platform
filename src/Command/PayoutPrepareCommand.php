<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PayoutPreparer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:payout:prepare',
    description: 'Build escrow payout intents for accepted/rejected tasks and write an intent file for settlement.',
)]
final class PayoutPrepareCommand extends Command
{
    public function __construct(
        private readonly PayoutPreparer $preparer,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of payouts to prepare in this run')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Batch id written to the intent (default: today, YYYY-MM-DD)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be prepared without writing to the database or disk');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = null;
        if (null !== $input->getOption('limit')) {
            $raw = (string) $input->getOption('limit');
            if (!ctype_digit($raw) || (int) $raw < 1) {
                $io->error('--limit must be a positive integer.');

                return Command::INVALID;
            }
            $limit = (int) $raw;
        }

        $lock = $this->lockFactory->createLockFromKey(new Key('app.payout-prepare'));
        if (!$lock->acquire()) {
            $io->warning('Another payout preparation is already running. Skipping.');

            return Command::SUCCESS;
        }

        try {
            $now = new \DateTimeImmutable();
            $date = $input->getOption('date');
            $batchId = null !== $date ? (string) $date : $now->format('Y-m-d');

            $result = $this->preparer->prepare(
                $now,
                $batchId,
                $limit,
                (bool) $input->getOption('dry-run'),
            );

            if ([] !== $result->prepared) {
                $io->table(
                    ['task', 'direction', 'amount (sun)', 'recipient'],
                    array_map(
                        static fn (array $row): array => [
                            $row['taskId'],
                            $row['direction'],
                            (string) $row['amountSun'],
                            $row['toAddress'],
                        ],
                        $result->prepared,
                    ),
                );
            }

            if ([] !== $result->failed) {
                $io->table(
                    ['task', 'error'],
                    array_map(
                        static fn (array $row): array => [$row['taskId'], $row['error']],
                        $result->failed,
                    ),
                );
            }

            if ([] !== $result->skipped) {
                $io->table(
                    ['task', 'skipped because'],
                    array_map(
                        static fn (array $row): array => [$row['taskId'], $row['reason']],
                        $result->skipped,
                    ),
                );
            }

            if ($result->isEmpty()) {
                $io->success('Nothing to prepare.');

                return Command::SUCCESS;
            }

            $summary = sprintf(
                '%d payout(s) prepared for %d sun in batch "%s".',
                \count($result->prepared),
                $result->preparedTotalSun(),
                $result->batchId,
            );
            if (null !== $result->intentPath) {
                $summary .= sprintf(' Intent: %s', $result->intentPath);
            } else {
                $summary .= ' (dry run, nothing written)';
            }

            $io->success($summary);

            return [] === $result->failed ? Command::SUCCESS : Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
