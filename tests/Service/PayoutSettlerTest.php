<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PayoutFailureKind;
use App\Entity\PayoutOutcome;
use App\Service\HdWalletMnemonicSigner;
use App\Service\OfflineTransferSigner;
use App\Service\PayoutBundleStorage;
use App\Service\PayoutSettler;
use App\Service\TronGatewayInterface;
use App\Tests\Helper\TronTransactionFixture;
use App\ValueObject\PayoutIntentBundle;
use App\ValueObject\PayoutIntentItem;
use App\ValueObject\PayoutReceipt;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the settlement machine (build+sign+broadcast+receipt) with the
 * TRON node replaced by a stub. Covers already-on-chain, fee shortage, key
 * mismatch, receipts and the re-run journal.
 */
final class PayoutSettlerTest extends TestCase
{
    private const MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
    private const ESCROW = 'TUEZSdKsoDHQMeZwihtdoBiN46zxhGWYdH';
    private const RECIPIENT = 'THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY';
    private const AMOUNT_SUN = 10_000_000;
    private const FEE_SUN = 100_000;
    private const BLOCK_NUMBER = 654321;

    private string $directory;
    private PayoutBundleStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/payout-settle-'.bin2hex(random_bytes(6));
        $this->storage = new PayoutBundleStorage($this->directory, sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testConfirmsAnIntentItemEndToEnd(): void
    {
        $result = $this->settler($this->gateway())->settle($this->intent(self::ESCROW), new \DateTimeImmutable(), self::MNEMONIC);

        self::assertCount(1, $result->items);
        $item = $result->items[0];
        self::assertSame(PayoutOutcome::Confirmed, $item->outcome);
        self::assertSame(64, strlen((string) $item->txId));
        self::assertSame(self::FEE_SUN, $item->feeSun);
        self::assertSame(self::BLOCK_NUMBER, $item->blockNumber);
        self::assertNotNull($result->reportPath);
        self::assertStringEndsWith('batch-settle.report.json', (string) $result->reportPath);
    }

    public function testReportsBroadcastWhenThereIsNoReceiptYet(): void
    {
        $result = $this->settler($this->gateway(['receipt' => null]))
            ->settle($this->intent(self::ESCROW), new \DateTimeImmutable(), self::MNEMONIC);

        self::assertSame(PayoutOutcome::Broadcast, $result->items[0]->outcome);
        self::assertNull($result->items[0]->feeSun);
    }

    public function testDoesNotBroadcastWhenTheNodeAlreadyHasTheTransaction(): void
    {
        $broadcasts = [];
        $gateway = $this->gateway([
            'hasTransaction' => true,
            'broadcastSigned' => static function (array $payload) use (&$broadcasts): string {
                $broadcasts[] = $payload;

                return $payload['txID'] ?? '';
            },
        ]);

        $result = $this->settler($gateway)->settle($this->intent(self::ESCROW), new \DateTimeImmutable(), self::MNEMONIC);

        self::assertSame(PayoutOutcome::Confirmed, $result->items[0]->outcome);
        self::assertSame([], $broadcasts);
    }

    public function testFailsPermanentlyWhenTheDerivedKeyDoesNotOwnTheEscrow(): void
    {
        $result = $this->settler($this->gateway())
            ->settle($this->intent('TSeJkUh4Qv67VNFwY8LaAxERygNdy6NQZK'), new \DateTimeImmutable(), self::MNEMONIC);

        $item = $result->items[0];
        self::assertSame(PayoutOutcome::Failed, $item->outcome);
        self::assertSame(PayoutFailureKind::Permanent, $item->failureKind);
        self::assertStringContainsString('does not match', (string) $item->error);
    }

    public function testFailsRetryablyWhenTheEscrowBalanceCannotCoverAmountPlusFees(): void
    {
        $result = $this->settler($this->gateway(['balanceSun' => self::AMOUNT_SUN + self::FEE_SUN - 1]))
            ->settle($this->intent(self::ESCROW), new \DateTimeImmutable(), self::MNEMONIC);

        $item = $result->items[0];
        self::assertSame(PayoutOutcome::Failed, $item->outcome);
        self::assertSame(PayoutFailureKind::Retryable, $item->failureKind);
        self::assertStringContainsString('holds', (string) $item->error);
    }

    public function testFailsPermanentlyWhenTheNodeTransferDisagreesWithTheIntent(): void
    {
        $broadcasts = [];
        $gateway = $this->gateway([
            'buildTransfer' => static fn (string $from, string $to, int $amount, \DateTimeImmutable $expiresAt) => TronTransactionFixture::unsignedTransfer(
                $from,
                $to,
                $amount + 1,
                (int) $expiresAt->format('Uv'),
            ),
            'broadcastSigned' => static function (array $payload) use (&$broadcasts): string {
                $broadcasts[] = $payload;

                return $payload['txID'] ?? '';
            },
        ]);

        $result = $this->settler($gateway)->settle($this->intent(self::ESCROW), new \DateTimeImmutable(), self::MNEMONIC);

        $item = $result->items[0];
        self::assertSame(PayoutOutcome::Failed, $item->outcome);
        self::assertSame(PayoutFailureKind::Permanent, $item->failureKind);
        self::assertStringContainsString('does not match the intent', (string) $item->error);
        self::assertSame([], $broadcasts);
    }

    public function testTheJournalPreventsADoubleBroadcastOnReRun(): void
    {
        $broadcasts = [];
        $gateway = $this->gateway([
            'broadcastSigned' => static function (array $payload) use (&$broadcasts): string {
                $broadcasts[] = $payload;

                return $payload['txID'] ?? '';
            },
        ]);

        $settler = $this->settler($gateway);
        $intent = $this->intent(self::ESCROW);

        $first = $settler->settle($intent, new \DateTimeImmutable(), self::MNEMONIC);
        $second = $settler->settle($intent, new \DateTimeImmutable(), self::MNEMONIC);

        self::assertSame(PayoutOutcome::Confirmed, $first->items[0]->outcome);
        self::assertSame($first->items[0]->txId, $second->items[0]->txId);
        self::assertCount(1, $broadcasts);
    }

    public function testRefusesAnIntentPreparedForAnotherNetwork(): void
    {
        $gateway = $this->createStub(TronGatewayInterface::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/configured for "nile"/');

        $intent = new PayoutIntentBundle('mainnet', 'batch-settle', new \DateTimeImmutable(), []);
        $this->settler($gateway)->settle($intent, new \DateTimeImmutable(), self::MNEMONIC);
    }

    private function settler(TronGatewayInterface $gateway): PayoutSettler
    {
        return new PayoutSettler(
            hdWallet: new HdWalletMnemonicSigner(),
            offlineSigner: new OfflineTransferSigner(),
            gateway: $gateway,
            storage: $this->storage,
            network: 'nile',
            ttl: '30 minutes',
            feeMarginPercent: 20,
        );
    }

    /**
     * A gateway stub preconfigured with a successful happy path. Each override
     * replaces one method's return value; override with a Closure for a callback.
     *
     * @param array<string, mixed> $overrides
     */
    private function gateway(array $overrides = []): TronGatewayInterface
    {
        $gateway = $this->createStub(TronGatewayInterface::class);

        $values = [
            'buildTransfer' => static fn (string $from, string $to, int $amount, \DateTimeImmutable $expiresAt) => TronTransactionFixture::unsignedTransfer(
                $from,
                $to,
                $amount,
                (int) $expiresAt->format('Uv'),
            ),
            'estimateFeeSun' => self::FEE_SUN,
            'balanceSun' => self::AMOUNT_SUN + self::FEE_SUN + 1_000_000,
            'hasTransaction' => false,
            'broadcastSigned' => str_repeat('ab', 32),
            'receipt' => new PayoutReceipt(str_repeat('ab', 32), true, self::BLOCK_NUMBER, self::FEE_SUN, 'SUCCESS'),
            'findTransfer' => ['from' => self::ESCROW, 'to' => self::RECIPIENT, 'amountSun' => self::AMOUNT_SUN],
        ];

        foreach ($values as $method => $value) {
            if (array_key_exists($method, $overrides)) {
                $value = $overrides[$method];
                unset($overrides[$method]);
            }

            $mocker = $gateway->method($method);
            $value instanceof \Closure ? $mocker->willReturnCallback($value) : $mocker->willReturn($value);
        }

        foreach ($overrides as $method => $value) {
            $mocker = $gateway->method($method);
            $value instanceof \Closure ? $mocker->willReturnCallback($value) : $mocker->willReturn($value);
        }

        return $gateway;
    }

    private function intent(string $fromAddress): PayoutIntentBundle
    {
        return new PayoutIntentBundle(
            network: 'nile',
            batchId: 'batch-settle',
            createdAt: new \DateTimeImmutable('2026-10-12 10:00:00'),
            items: [new PayoutIntentItem(
                payoutId: 'payout-1',
                taskId: 'task-1',
                direction: 'worker',
                walletIndex: 0,
                fromAddress: $fromAddress,
                toAddress: self::RECIPIENT,
                amountSun: self::AMOUNT_SUN,
            )],
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }
}
