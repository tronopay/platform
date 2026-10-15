<?php

namespace App\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores uploaded files on the local disk under the configured uploads root.
 *
 * @see ui-spec.md §9 — replace with a S3 adapter later, the schema stays the same.
 */
final class LocalFileStorage implements FileStorageInterface
{
    public function __construct(
        #[Autowire(param: 'uploads_dir')]
        private readonly string $uploadsDir,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function put(string $virtualPath, UploadedFile|string $contents): void
    {
        $target = $this->resolve($virtualPath);

        if ($contents instanceof UploadedFile) {
            // move() renames the temp file into place and creates missing directories.
            $contents->move(\dirname($target), basename($target));

            return;
        }

        $dir = \dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create uploads directory "%s".', $dir));
        }

        if (false === file_put_contents($target, $contents)) {
            throw new \RuntimeException(sprintf('Unable to write uploaded file "%s".', $target));
        }
    }

    public function get(string $virtualPath): string
    {
        $target = $this->resolve($virtualPath);
        if (!is_file($target) || false === ($contents = file_get_contents($target))) {
            throw new \RuntimeException(sprintf('Uploaded file "%s" is missing.', $virtualPath));
        }

        return $contents;
    }

    public function delete(string $virtualPath): void
    {
        $target = $this->resolve($virtualPath);
        if (is_file($target) && !unlink($target)) {
            throw new \RuntimeException(sprintf('Unable to remove uploaded file "%s".', $virtualPath));
        }
    }

    public function exists(string $virtualPath): bool
    {
        return is_file($this->resolve($virtualPath));
    }

    public function publicUrl(string $virtualPath): ?string
    {
        // Local files are served through the application (App\Controller\CabinetController::downloadFile).
        return null;
    }

    private function resolve(string $virtualPath): string
    {
        $root = $this->uploadsDir;
        if ('' !== $root && '/' !== $root[0]) {
            $root = $this->projectDir.'/'.$root;
        }

        return rtrim($root, '/').'/'.$virtualPath;
    }
}