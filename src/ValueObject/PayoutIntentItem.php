<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * One payment instruction inside a {@see PayoutIntentBundle}.
 *
 * It is the only thing that crosses the air gap from the online server to the
 * machine holding the keys: where the money goes and how much. It carries no
 * transaction bytes, no signature and no key material — the machine builds the
 * transaction itself, when it settles the batch, so the escrow `ref_block` and
 * `timestamp` are always fresh.
 */
final readonly class PayoutIntentItem
{
    public function __construct(
        public string $payoutId,
        public string $taskId,
        public string $direction,
        public int $walletIndex,
        public string $fromAddress,
        public string $toAddress,
        public int $amountSun,
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
            'direction' => $this->direction,
            'walletIndex' => $this->walletIndex,
            'fromAddress' => $this->fromAddress,
            'toAddress' => $this->toAddress,
            'amountSun' => $this->amountSun,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            payoutId: (string) ($data['payoutId'] ?? ''),
            taskId: (string) ($data['taskId'] ?? ''),
            direction: (string) ($data['direction'] ?? ''),
            walletIndex: (int) ($data['walletIndex'] ?? 0),
            fromAddress: (string) ($data['fromAddress'] ?? ''),
            toAddress: (string) ($data['toAddress'] ?? ''),
            amountSun: (int) ($data['amountSun'] ?? 0),
        );
    }
}
