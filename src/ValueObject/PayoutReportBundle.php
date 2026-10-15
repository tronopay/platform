<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * The machine→server hand-off for one batch (`<batchId>.report.json`).
 *
 * The report covers every item of the matching intent, success or error, so
 * the server can always close the loop on each `payoutId`. The same checksum
 * scheme as the intent protects the file in transit.
 */
final readonly class PayoutReportBundle
{
    public const VERSION = 2;
    public const TYPE = 'report';

    /**
     * @param list<PayoutReportItem> $items
     */
    public function __construct(
        public string $network,
        public string $batchId,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $settledAt,
        public array $items,
        public ?string $checksum = null,
    ) {
    }

    public function computeChecksum(): string
    {
        return hash('sha256', json_encode(
            array_map(static fn (PayoutReportItem $item): array => $item->toArray(), $this->items),
            JSON_THROW_ON_ERROR,
        ));
    }

    public function hasValidChecksum(): bool
    {
        return $this->checksum !== null
            && hash_equals($this->checksum, $this->computeChecksum());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'version' => self::VERSION,
            'network' => $this->network,
            'batchId' => $this->batchId,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'settledAt' => $this->settledAt->format(\DateTimeInterface::ATOM),
            'checksum' => $this->computeChecksum(),
            'items' => array_map(static fn (PayoutReportItem $item): array => $item->toArray(), $this->items),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $version = (int) ($data['version'] ?? 0);
        if (self::VERSION !== $version) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported payout report version %d (expected %d).',
                $version,
                self::VERSION,
            ));
        }

        $rawItems = $data['items'] ?? [];
        if (!\is_array($rawItems) || !array_is_list($rawItems)) {
            throw new \InvalidArgumentException('A payout report must contain a list of items.');
        }

        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!\is_array($rawItem)) {
                throw new \InvalidArgumentException('Every payout report item must be an object.');
            }
            $items[] = PayoutReportItem::fromArray($rawItem);
        }

        return new self(
            network: (string) ($data['network'] ?? ''),
            batchId: (string) ($data['batchId'] ?? ''),
            createdAt: self::parseAtom($data['createdAt'] ?? null),
            settledAt: self::parseAtom($data['settledAt'] ?? null),
            items: $items,
            checksum: \is_string($data['checksum'] ?? null) ? $data['checksum'] : null,
        );
    }

    private static function parseAtom(mixed $value): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) $value);

        return $parsed !== false ? $parsed : new \DateTimeImmutable();
    }
}
