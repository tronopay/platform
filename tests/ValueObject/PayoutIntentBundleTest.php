<?php

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\ValueObject\PayoutIntentBundle;
use App\ValueObject\PayoutIntentItem;
use PHPUnit\Framework\TestCase;

final class PayoutIntentBundleTest extends TestCase
{
    public function testRoundTripKeepsTheChecksumValid(): void
    {
        $bundle = $this->bundle([$this->item(10_000_000)]);
        $decoded = PayoutIntentBundle::fromArray($bundle->toArray());

        self::assertTrue($decoded->hasValidChecksum());
        self::assertSame('batch-7', $decoded->batchId);
        self::assertSame('nile', $decoded->network);
        self::assertCount(1, $decoded->items);
        self::assertSame('TEsUWfRqtr6jmjHuxvrEMVzoSh5Mz52TzT', $decoded->items[0]->fromAddress);
        self::assertSame('THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY', $decoded->items[0]->toAddress);
        self::assertSame(3, $decoded->items[0]->walletIndex);
        self::assertSame(10_000_000, $decoded->items[0]->amountSun);
    }

    public function testTheIntentWireFormatUsesTheDocumentedKeys(): void
    {
        $data = $this->bundle([$this->item(10_000_000)])->toArray();

        self::assertSame('intent', $data['type']);
        self::assertSame(2, $data['version']);
        self::assertArrayHasKey('fromAddress', $data['items'][0]);
        self::assertArrayHasKey('toAddress', $data['items'][0]);
        self::assertArrayNotHasKey('from', $data['items'][0]);
        self::assertArrayNotHasKey('to', $data['items'][0]);
    }

    public function testChecksumChangesWhenAnAmountIsEditedInTransit(): void
    {
        $bundle = $this->bundle([$this->item(10_000_000)]);
        $data = $bundle->toArray();
        $data['items'][0]['amountSun'] = 990_000_000;

        $tampered = PayoutIntentBundle::fromArray($data);

        self::assertFalse($tampered->hasValidChecksum());
    }

    public function testChecksumIsStableForIdenticalItems(): void
    {
        $first = $this->bundle([$this->item(10_000_000)]);
        $second = $this->bundle([$this->item(10_000_000)]);

        self::assertSame($first->computeChecksum(), $second->computeChecksum());
    }

    public function testAnUnknownVersionIsRejected(): void
    {
        $data = $this->bundle([])->toArray();
        $data['version'] = 99;

        $this->expectException(\InvalidArgumentException::class);
        PayoutIntentBundle::fromArray($data);
    }

    /**
     * @param list<PayoutIntentItem> $items
     */
    private function bundle(array $items): PayoutIntentBundle
    {
        return new PayoutIntentBundle(
            network: 'nile',
            batchId: 'batch-7',
            createdAt: new \DateTimeImmutable('2026-10-12 10:00:00'),
            items: $items,
        );
    }

    private function item(int $amountSun): PayoutIntentItem
    {
        return new PayoutIntentItem(
            payoutId: 'p-1',
            taskId: 't-1',
            direction: 'worker',
            walletIndex: 3,
            fromAddress: 'TEsUWfRqtr6jmjHuxvrEMVzoSh5Mz52TzT',
            toAddress: 'THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY',
            amountSun: $amountSun,
        );
    }
}
