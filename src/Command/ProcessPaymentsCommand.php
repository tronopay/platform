<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PaymentProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:payment:process',
    description: 'Mark paid tasks as prepaid and cancel tasks whose payment window expired.',
)]
final class ProcessPaymentsCommand extends Command
{
    public function __construct(
        private readonly PaymentProcessor $paymentProcessor,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->paymentProcessor->process();
        if (null === $result) {
            $io->warning('Another payment processing run is already in progress. Skipping.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Prepaid %d task(s); cancelled %d expired task(s).', $result['prepaid'], $result['cancelled']));

        return Command::SUCCESS;
    }
}
