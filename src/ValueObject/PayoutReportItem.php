<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Entity\PayoutOutcome;
use App\Entity\PayoutFailureKind;

/**
 * The machine→server hand-off for one batch (`<batchId>.report.json`).
 */
final readonly class PayoutReportItem
{
    public function __construct(
        public string $taskId,
        public string $payoutId,
        public PayoutOutcome $outcome,
        public ?string $txId = null,
        public ?int $feeSun = null,
        public ?int $blockNumber = null,
        public ?PayoutFailureKind $failureKind = null,
        public ?string $error = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'taskId' => $this->taskId,
            'payoutId' => $this->payoutId,
            'outcome' => $this->outcome->value,
            'txId' => $this->txId,
            'feeSun' => $this->feeSun,
            'blockNumber' => $this->blockNumber,
            'failureKind' => $this->failureKind?->value,
            'error' => $this->error,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $outcome = PayoutOutcome::tryFrom((string) ($data['outcome'] ?? ''));
        if (null === $outcome) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown payout outcome "%s"; expected one of: %s.',
                (string) ($data['outcome'] ?? ''),
                implode(', ', array_column(PayoutOutcome::cases(), 'value')),
            ));
        }

        $failureKind = null;
        if (\is_string($data['failureKind'] ?? null) && $data['failureKind'] !== '') {
            $failureKind = PayoutFailureKind::tryFrom($data['failureKind']);
            if (null === $failureKind) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown payout failure kind "%s".',
                    $data['failureKind'],
                ));
            }
        }

        return new self(
            taskId: (string) ($data['taskId'] ?? ''),
            payoutId: (string) ($data['payoutId'] ?? ''),
            outcome: $outcome,
            txId: \is_string($data['txId'] ?? null) && $data['txId'] !== '' ? $data['txId'] : null,
            feeSun: isset($data['feeSun']) && \is_numeric($data['feeSun']) ? (int) $data['feeSun'] : null,
            blockNumber: isset($data['blockNumber']) && \is_numeric($data['blockNumber']) ? (int) $data['blockNumber'] : null,
            failureKind: $failureKind,
            error: \is_string($data['error'] ?? null) ? $data['error'] : null,
        );
    }
}
