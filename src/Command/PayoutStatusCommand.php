<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Payout;
use App\Entity\PayoutStatus;
use App\Repository\PayoutRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:payout:status',
    description: 'List escrow payouts and flag the ones that need attention.',
)]
final class PayoutStatusCommand extends Command
{
    public function __construct(private readonly PayoutRepository $payouts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Only this status (prepared, broadcast, confirmed, failed, abandoned)')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Only this batch id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of rows (default 100)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = null;
        if (null !== $input->getOption('status')) {
            $status = PayoutStatus::tryFrom((string) $input->getOption('status'));
            if (null === $status) {
                $io->error(sprintf(
                    'Unknown status "%s"; expected one of: %s.',
                    (string) $input->getOption('status'),
                    implode(', ', array_column(PayoutStatus::cases(), 'value')),
                ));

                return Command::INVALID;
            }
        }

        $limit = null !== $input->getOption('limit') ? (int) $input->getOption('limit') : 100;
        $batchId = null !== $input->getOption('batch') ? (string) $input->getOption('batch') : null;

        $now = new \DateTimeImmutable();
        $payouts = $this->payouts->findForReport($status, $batchId, $limit);

        if ([] === $payouts) {
            $io->success('No payouts found.');

            return Command::SUCCESS;
        }

        $attention = 0;
        $rows = [];
        foreach ($payouts as $payout) {
            $needsAttention = $this->needsAttention($payout, $now);
            if ($needsAttention) {
                ++$attention;
            }

            $rows[] = [
                (string) $payout->getTask()?->getId(),
                $payout->getDirection()?->value ?? '-',
                $payout->getStatus()->value,
                (string) $payout->getAmountSun(),
                $payout->getToAddress() ?? '-',
                $payout->getTxId() ?? '-',
                $payout->getUpdatedAt()?->format('Y-m-d H:i') ?? '-',
                $needsAttention ? 'yes' : '',
            ];
        }

        $io->table(['task', 'direction', 'status', 'amount (sun)', 'recipient', 'txID', 'updated', 'attention'], $rows);

        $io->writeln(sprintf('%d payout(s) listed, %d need attention.', \count($payouts), $attention));

        return Command::SUCCESS;
    }

    private function needsAttention(Payout $payout, \DateTimeImmutable $now): bool
    {
        // Only a failed payout requires an operator: everything else is either
        // in flight (prepared), settled (broadcast/confirmed) or deliberately
        // closed (abandoned).
        return PayoutStatus::Failed === $payout->getStatus();
    }
}
