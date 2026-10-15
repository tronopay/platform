<?php

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\Entity\PayoutFailureKind;
use App\Entity\PayoutOutcome;
use App\ValueObject\PayoutReportBundle;
use App\ValueObject\PayoutReportItem;
use PHPUnit\Framework\TestCase;

final class PayoutReportBundleTest extends TestCase
{
    public function testRoundTripKeepsTheChecksumValid(): void
    {
        $bundle = $this->bundle([$this->item(PayoutOutcome::Confirmed, txId: str_repeat('ab', 32), feeSun: 123, blockNumber: 654321)]);

        $decoded = PayoutReportBundle::fromArray($bundle->toArray());

        self::assertTrue($decoded->hasValidChecksum());
        self::assertSame('batch-7', $decoded->batchId);
        self::assertSame('nile', $decoded->network);
        self::assertCount(1, $decoded->items);
        self::assertSame(PayoutOutcome::Confirmed, $decoded->items[0]->outcome);
        self::assertSame(str_repeat('ab', 32), $decoded->items[0]->txId);
        self::assertSame(123, $decoded->items[0]->feeSun);
        self::assertSame(654321, $decoded->items[0]->blockNumber);
    }

    public function testAFailedItemRoundTripsItsFailureKindAndError(): void
    {
        $bundle = $this->bundle([$this->item(
            PayoutOutcome::Failed,
            failureKind: PayoutFailureKind::Permanent,
            error: 'the recipient address is invalid',
        )]);

        $item = PayoutReportBundle::fromArray($bundle->toArray())->items[0];

        self::assertSame(PayoutOutcome::Failed, $item->outcome);
        self::assertSame(PayoutFailureKind::Permanent, $item->failureKind);
        self::assertSame('the recipient address is invalid', $item->error);
        self::assertNull($item->txId);
    }

    public function testAnUnknownOutcomeIsRejected(): void
    {
        $data = $this->bundle([])->toArray();
        $data['items'] = [[
            'taskId' => 't-1',
            'payoutId' => 'p-1',
            'outcome' => 'exploded',
        ]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/outcome/');
        PayoutReportBundle::fromArray($data);
    }

    public function testAnUnknownFailureKindIsRejected(): void
    {
        $data = $this->bundle([])->toArray();
        $data['items'] = [[
            'taskId' => 't-1',
            'payoutId' => 'p-1',
            'outcome' => 'failed',
            'failureKind' => 'sometimes',
        ]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/failure kind/');
        PayoutReportBundle::fromArray($data);
    }

    /**
     * @param list<PayoutReportItem> $items
     */
    private function bundle(array $items): PayoutReportBundle
    {
        return new PayoutReportBundle(
            network: 'nile',
            batchId: 'batch-7',
            createdAt: new \DateTimeImmutable('2026-10-12 10:00:00'),
            settledAt: new \DateTimeImmutable('2026-10-12 10:05:00'),
            items: $items,
        );
    }

    private function item(
        PayoutOutcome $outcome,
        ?string $txId = null,
        ?int $feeSun = null,
        ?int $blockNumber = null,
        ?PayoutFailureKind $failureKind = null,
        ?string $error = null,
    ): PayoutReportItem {
        return new PayoutReportItem(
            taskId: 't-1',
            payoutId: 'p-1',
            outcome: $outcome,
            txId: $txId,
            feeSun: $feeSun,
            blockNumber: $blockNumber,
            failureKind: $failureKind,
            error: $error,
        );
    }
}
