<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched periodically by the Symfony Scheduler to sweep the payment window:
 * marks paid tasks as prepaid and cancels tasks whose window has expired.
 * See App\Scheduler\Schedule and App\Service\PaymentProcessor.
 */
final readonly class ProcessPaymentsMessage
{
}
