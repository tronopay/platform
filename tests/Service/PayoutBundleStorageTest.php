<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PayoutOutcome;
use App\Service\PayoutBundleStorage;
use App\ValueObject\PayoutIntentBundle;
use App\ValueObject\PayoutIntentItem;
use App\ValueObject\PayoutReportBundle;
use App\ValueObject\PayoutReportItem;
use PHPUnit\Framework\TestCase;

final class PayoutBundleStorageTest extends TestCase
{
    private string $directory;
    private PayoutBundleStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/payout-storage-'.bin2hex(random_bytes(6));
        $this->storage = new PayoutBundleStorage($this->directory, sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testWritesAndReadsAnIntent(): void
    {
        $path = $this->storage->writeIntent($this->intent());

        self::assertFileExists($path);
        self::assertStringEndsWith('batch-1.intent.json', $path);
        self::assertSame('0644', substr(sprintf('%o', fileperms($path)), -4));

        $intent = $this->storage->readIntent($path);
        self::assertSame('batch-1', $intent->batchId);
        self::assertTrue($intent->hasValidChecksum());
        self::assertSame('p-1', $intent->items[0]->payoutId);
    }

    public function testWritesAReportToAnExplicitOperatorPath(): void
    {
        $path = $this->storage->writeReportTo($this->directory.'/usb/batch-1.report.json', $this->report());

        self::assertFileExists($path);
        self::assertSame('batch-1', $this->storage->readReport($path)->batchId);
    }

    public function testRefusesAnIntentEditedInTransit(): void
    {
        $path = $this->storage->writeIntent($this->intent());
        $json = (string) file_get_contents($path);
        file_put_contents($path, str_replace('10000000', '99000000', $json));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/checksum/');
        $this->storage->readIntent($path);
    }

    public function testRefusesAReportEditedInTransit(): void
    {
        $path = $this->storage->writeReportTo($this->directory.'/batch-1.report.json', $this->report());
        $json = (string) file_get_contents($path);
        file_put_contents($path, str_replace('654321', '999999', $json));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/checksum/');
        $this->storage->readReport($path);
    }

    public function testRefusesAReportThatIsActuallyAnIntent(): void
    {
        $path = $this->storage->writeIntent($this->intent());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a "report"/');
        $this->storage->readReport($path);
    }

    public function testRefusesPathsWithTraversalSegments(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\.\./');
        $this->storage->readIntent('../../etc/passwd');
    }

    public function testRefusesAMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $this->storage->readIntent('var/payout/nope.intent.json');
    }

    private function intent(): PayoutIntentBundle
    {
        return new PayoutIntentBundle(
            network: 'nile',
            batchId: 'batch-1',
            createdAt: new \DateTimeImmutable('2026-10-12 10:00:00'),
            items: [new PayoutIntentItem(
                payoutId: 'p-1',
                taskId: 't-1',
                direction: 'worker',
                walletIndex: 3,
                fromAddress: 'TEsUWfRqtr6jmjHuxvrEMVzoSh5Mz52TzT',
                toAddress: 'THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY',
                amountSun: 10_000_000,
            )],
        );
    }

    private function report(): PayoutReportBundle
    {
        return new PayoutReportBundle(
            network: 'nile',
            batchId: 'batch-1',
            createdAt: new \DateTimeImmutable('2026-10-12 10:00:00'),
            settledAt: new \DateTimeImmutable('2026-10-12 10:05:00'),
            items: [new PayoutReportItem(
                taskId: 't-1',
                payoutId: 'p-1',
                outcome: PayoutOutcome::Confirmed,
                txId: str_repeat('ab', 32),
                feeSun: 123,
                blockNumber: 654321,
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
            $path = $entry->getPathname();
            $entry->isDir() ? @rmdir($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
