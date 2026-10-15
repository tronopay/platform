<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * A set of escrow payment intents written by the online server in one batch
 * file (`<batchId>.intent.json`).
 *
 * The bundle is the server→machine hand-off. It carries a SHA-256 checksum over
 * the ordered items so accidental or deliberate edits in transit are detected
 * before the machine derives keys or sends anything on chain.
 */
final readonly class PayoutIntentBundle
{
    public const VERSION = 2;
    public const TYPE = 'intent';

    /**
     * @param list<PayoutIntentItem> $items
     */
    public function __construct(
        public string $network,
        public string $batchId,
        public \DateTimeImmutable $createdAt,
        public array $items,
        public ?string $checksum = null,
    ) {
    }

    /**
     * Deterministic checksum of the ordered items.
     */
    public function computeChecksum(): string
    {
        return hash('sha256', $this->itemsJson());
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
            'checksum' => $this->computeChecksum(),
            'items' => array_map(static fn (PayoutIntentItem $item): array => $item->toArray(), $this->items),
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
                'Unsupported payout intent version %d (expected %d).',
                $version,
                self::VERSION,
            ));
        }

        $rawItems = $data['items'] ?? [];
        if (!\is_array($rawItems) || !array_is_list($rawItems)) {
            throw new \InvalidArgumentException('A payout intent must contain a list of items.');
        }

        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!\is_array($rawItem)) {
                throw new \InvalidArgumentException('Every payout intent item must be an object.');
            }
            $items[] = PayoutIntentItem::fromArray($rawItem);
        }

        return new self(
            network: (string) ($data['network'] ?? ''),
            batchId: (string) ($data['batchId'] ?? ''),
            createdAt: self::parseAtom($data['createdAt'] ?? null),
            items: $items,
            checksum: \is_string($data['checksum'] ?? null) ? $data['checksum'] : null,
        );
    }

    private function itemsJson(): string
    {
        return json_encode(
            array_map(static fn (PayoutIntentItem $item): array => $item->toArray(), $this->items),
            JSON_THROW_ON_ERROR,
        );
    }

    private static function parseAtom(mixed $value): \DateTimeImmutable
    {
        $createdAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) $value);

        return $createdAt !== false ? $createdAt : new \DateTimeImmutable();
    }
}
