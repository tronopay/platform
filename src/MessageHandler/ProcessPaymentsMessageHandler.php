<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessPaymentsMessage;
use App\Service\PaymentProcessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessPaymentsMessageHandler
{
    public function __construct(private PaymentProcessor $paymentProcessor)
    {
    }

    public function __invoke(ProcessPaymentsMessage $message): void
    {
        // The lock + run-once semantics live in PaymentProcessor; the scheduled
        // handler intentionally ignores its (empty) message payload.
        $this->paymentProcessor->process();
    }
}
