<?php

namespace App\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Storage abstraction for the executor-cabinet attachments.
 *
 * The virtual path is an opaque logical key (e.g. "tasks/{taskUuid}/{fileUuid}.jpg")
 * stored in the database — swapping the local disk for S3 later only requires a new
 * adapter implementing this interface, without any schema or cabinet changes.
 */
interface FileStorageInterface
{
    public function put(string $virtualPath, UploadedFile|string $contents): void;

    public function get(string $virtualPath): string;

    public function delete(string $virtualPath): void;

    public function exists(string $virtualPath): bool;

    /**
     * Returns a public URL for the object, or null when the file must be served
     * through the application (e.g. the local-disk adapter).
     */
    public function publicUrl(string $virtualPath): ?string;
}