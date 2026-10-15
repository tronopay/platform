<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PayoutBundleStorage;
use App\Service\PayoutSettler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:payout:settle',
    description: 'Settle a payout intent: build, sign, broadcast and write a report (run on the machine holding the keys).',
)]
final class PayoutSettleCommand extends Command
{
    public function __construct(
        private readonly PayoutBundleStorage $storage,
        private readonly PayoutSettler $settler,
        #[Autowire('%env(TRON_MNEMONIC)%')]
        private readonly string $mnemonicFromEnv = '',
        #[Autowire('%env(TRON_PASSPHRASE)%')]
        private readonly string $passphraseFromEnv = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('in', null, InputOption::VALUE_REQUIRED, 'Path to the intent file written by app:payout:prepare')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Where to write the report (default: <batchId>.report.json next to the intent)')
            ->addOption('mnemonic-file', null, InputOption::VALUE_REQUIRED, 'File containing the BIP-39 mnemonic (default: hidden prompt or TRON_MNEMONIC)')
            ->addOption('passphrase-file', null, InputOption::VALUE_REQUIRED, 'File containing the BIP-39 passphrase, if one was used')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Verify and estimate without broadcasting or writing a report');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $in = $input->getOption('in');
        if (null === $in || '' === trim((string) $in)) {
            $io->error('--in is required.');

            return Command::INVALID;
        }
        $in = (string) $in;

        try {
            $intent = $this->storage->readIntent($in);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ([] === $intent->items) {
            $io->error('This intent has no items to settle.');

            return Command::FAILURE;
        }

        try {
            $mnemonic = $this->resolveMnemonic($input, $io);
            $passphrase = $this->resolvePassphrase($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $out = $input->getOption('out');
        $reportPath = null !== $out && '' !== trim((string) $out) ? (string) $out : '';

        try {
            $result = $this->settler->settle(
                $intent,
                new \DateTimeImmutable(),
                $mnemonic,
                $passphrase,
                (bool) $input->getOption('dry-run'),
                $reportPath,
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($result->items as $item) {
            $rows[] = [
                $item->taskId,
                $item->payoutId,
                $item->outcome->value,
                $item->txId ?? '-',
                null !== $item->feeSun ? (string) $item->feeSun : '-',
                $item->error ?? '',
            ];
        }
        if ([] !== $rows) {
            $io->table(['task', 'payout', 'outcome', 'txID', 'fee (sun)', 'error'], $rows);
        }

        if ($result->dryRun) {
            $io->success(sprintf(
                '%d confirmed, %d failed in batch "%s" (dry run, nothing sent or written).',
                $result->confirmedCount(),
                $result->failedCount(),
                $result->batchId,
            ));

            return $result->failedCount() === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        $io->success(sprintf(
            'Settled batch "%s": %d confirmed, %d failed. Report: %s',
            $result->batchId,
            $result->confirmedCount(),
            $result->failedCount(),
            $result->reportPath,
        ));
        $io->writeln('Carry the report back to the online server and run: app:payout:apply-report --intent <intent> --report <report>');

        return $result->failedCount() === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function resolveMnemonic(InputInterface $input, SymfonyStyle $io): string
    {
        $file = $input->getOption('mnemonic-file');
        if (null !== $file) {
            $content = @file_get_contents((string) $file);
            if (false === $content) {
                throw new \RuntimeException(sprintf('Could not read the mnemonic file "%s".', (string) $file));
            }

            $mnemonic = trim($content);
            if ('' === $mnemonic) {
                throw new \RuntimeException('The mnemonic file is empty.');
            }

            return $mnemonic;
        }

        $fromEnv = trim($this->mnemonicFromEnv);
        if ('' !== $fromEnv) {
            return $fromEnv;
        }

        if (!$input->isInteractive()) {
            throw new \RuntimeException('No mnemonic available: pass --mnemonic-file, set TRON_MNEMONIC, or run interactively.');
        }

        $mnemonic = $io->askHidden('Escrow wallet mnemonic');
        if (null === $mnemonic || '' === trim($mnemonic)) {
            throw new \RuntimeException('No mnemonic entered.');
        }

        return trim($mnemonic);
    }

    private function resolvePassphrase(InputInterface $input): string
    {
        $file = $input->getOption('passphrase-file');
        if (null !== $file) {
            $content = @file_get_contents((string) $file);
            if (false === $content) {
                throw new \RuntimeException(sprintf('Could not read the passphrase file "%s".', (string) $file));
            }

            return rtrim($content, "\r\n");
        }

        return rtrim($this->passphraseFromEnv, "\r\n");
    }
}
