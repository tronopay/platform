<?php

declare(strict_types=1);

namespace App\Service;

use App\ValueObject\PayoutIntentBundle;
use App\ValueObject\PayoutReportBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads and writes payout intent/report JSON files.
 *
 * The online server writes intents and reads reports; the machine holding the
 * keys, which has no database, reads intents and writes reports. Writes go to
 * the configured payout directory or an explicit operator path. Reads reject
 * `..` segments, require a regular file and always validate version + checksum,
 * so a tampered file is refused no matter where it came from.
 */
final readonly class PayoutBundleStorage
{
    private string $directory;

    public function __construct(
        #[Autowire('%payouts_dir%')]
        string $payoutsDir,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
        $this->directory = rtrim(
            str_starts_with($payoutsDir, '/') ? $payoutsDir : $projectDir.'/'.$payoutsDir,
            '/',
        );
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Writes an intent bundle for a batch into the configured payout directory.
     */
    public function writeIntent(PayoutIntentBundle $bundle): string
    {
        $this->ensureDirectory();

        $path = $this->directory.'/'.$this->safeName($bundle->batchId).'.intent.json';
        $this->put($path, $bundle->toArray());

        return $path;
    }

    /**
     * Reads and validates an intent bundle from an explicit path.
     */
    public function readIntent(string $path): PayoutIntentBundle
    {
        $bundle = PayoutIntentBundle::fromArray($this->readObject($path, PayoutIntentBundle::TYPE));

        if (!$bundle->hasValidChecksum()) {
            throw new \RuntimeException(sprintf('The payout intent "%s" failed its checksum: the file was modified in transit.', $path));
        }

        return $bundle;
    }

    /**
     * Writes a report bundle to an explicit operator path (the machine has no
     * database and hands the file back over the air gap).
     */
    public function writeReportTo(string $path, PayoutReportBundle $bundle): string
    {
        if (str_contains($path, '..')) {
            throw new \RuntimeException(sprintf('Refusing to write to "%s": the path must not contain "..".', $path));
        }

        $target = str_starts_with($path, '/') ? $path : $this->projectDir.'/'.$path;
        $directory = \dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create the directory "%s".', $directory));
        }

        $this->put($target, $bundle->toArray());

        return $target;
    }

    /**
     * Reads and validates a report bundle from an explicit path.
     */
    public function readReport(string $path): PayoutReportBundle
    {
        $bundle = PayoutReportBundle::fromArray($this->readObject($path, PayoutReportBundle::TYPE));

        if (!$bundle->hasValidChecksum()) {
            throw new \RuntimeException(sprintf('The payout report "%s" failed its checksum: the file was modified in transit.', $path));
        }

        return $bundle;
    }

    /**
     * @return array<string, mixed>
     */
    private function readObject(string $path, string $type): array
    {
        $resolved = $this->resolveReadPath($path);
        $json = file_get_contents($resolved);
        if (false === $json) {
            throw new \RuntimeException(sprintf('Could not read the payout "%s" "%s".', $type, $path));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('The payout "%s" "%s" is not valid JSON.', $type, $path), 0, $exception);
        }

        if (!\is_array($data)) {
            throw new \RuntimeException(sprintf('The payout "%s" "%s" is not a JSON object.', $type, $path));
        }

        if (($data['type'] ?? null) !== $type) {
            throw new \RuntimeException(sprintf('The payout file "%s" is not a "%s".', $path, $type));
        }

        return $data;
    }

    private function put(string $path, array $data): void
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        if (false === file_put_contents($path, $json."\n")) {
            throw new \RuntimeException(sprintf('Could not write the payout file "%s".', $path));
        }

        @chmod($path, 0644);
    }

    private function resolveReadPath(string $path): string
    {
        if (str_contains($path, '..')) {
            throw new \RuntimeException(sprintf('Refusing to read "%s": the path must not contain "..".', $path));
        }

        $candidate = str_starts_with($path, '/') ? $path : $this->projectDir.'/'.$path;
        $resolved = realpath($candidate);
        if (false === $resolved || !is_file($resolved)) {
            throw new \RuntimeException(sprintf('Payout file not found: %s', $path));
        }

        return $resolved;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Could not create the payout directory "%s".', $this->directory));
        }
    }

    private function safeName(string $batchId): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $batchId) ?? '';

        return $name !== '' ? $name : 'batch';
    }
}
