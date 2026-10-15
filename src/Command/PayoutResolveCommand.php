<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PayoutStatus;
use App\Repository\PayoutRepository;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:payout:resolve',
    description: 'Abandon a payout that cannot be settled so the task may be prepared again.',
)]
final class PayoutResolveCommand extends Command
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly PayoutRepository $payouts,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task', InputArgument::REQUIRED, 'Task UUID whose payout needs attention')
            ->addOption('abandon', null, InputOption::VALUE_NONE, 'Close the payout without sending money; it may be prepared again')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Note stored with the resolution');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('abandon')) {
            $io->error('Pass --abandon to close the payout.');

            return Command::INVALID;
        }

        $rawTaskId = (string) $input->getArgument('task');
        if (!Uuid::isValid($rawTaskId)) {
            $io->error(sprintf('"%s" is not a valid task UUID.', $rawTaskId));

            return Command::INVALID;
        }

        $task = $this->tasks->find(Uuid::fromString($rawTaskId));
        if (null === $task) {
            $io->error(sprintf('Task %s was not found.', $rawTaskId));

            return Command::FAILURE;
        }

        $payout = $this->payouts->findOneByTask($task);
        if (null === $payout) {
            $io->error(sprintf('Task %s has no payout to resolve.', $rawTaskId));

            return Command::FAILURE;
        }

        if (PayoutStatus::Confirmed === $payout->getStatus()) {
            $io->error('The money of this payout is confirmed on chain; it can no longer be abandoned.');

            return Command::FAILURE;
        }

        $now = new \DateTimeImmutable();
        $reason = null !== $input->getOption('reason') ? (string) $input->getOption('reason') : null;

        $payout
            ->setStatus(PayoutStatus::Abandoned)
            ->setAbandonedAt($now)
            ->setError($reason ?? 'abandoned by hand')
            ->touch($now);
        $this->entityManager->flush();

        $io->success(sprintf('Payout for task %s abandoned.', $rawTaskId));

        return Command::SUCCESS;
    }
}
