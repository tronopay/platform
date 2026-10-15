<?php

namespace App\Service;

use App\Entity\Task;
use App\Entity\TaskResult;
use App\Entity\TaskResultFile;
use App\Entity\TaskStatus;
use App\Entity\Worker;
use App\Storage\FileStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Saves the result of an assigned task: mandatory text + optional files.
 *
 * @see ui-spec.md §10
 */
final readonly class TaskResultService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FileStorageInterface $storage,
        #[Autowire(param: 'task_result_file_max_count')]
        private int $maxFiles,
    ) {
    }

    /**
     * @param UploadedFile[] $files         new uploads to append
     * @param string[]       $removeFileIds existing file UUIDs to delete
     *
     * @throws \RuntimeException when the submission is not allowed or exceeds limits
     */
    public function save(Task $task, Worker $worker, string $message, array $files, array $removeFileIds = []): TaskResult
    {
        if (TaskStatus::Assigned !== $task->getStatus() || $task->getAssignedTo()?->getId() !== $worker->getId()) {
            throw new \RuntimeException('The task is not available for result submission.');
        }

        $result = $task->getResult();
        if (null === $result) {
            $result = new TaskResult();
            $result->setTask($task);
            $task->setResult($result);
            $this->entityManager->persist($result);
        }

        $result->setMessage($message);
        $result->setSubmittedAt(new \DateTimeImmutable());

        foreach ($removeFileIds as $fileId) {
            $storedFile = $this->findFile($result, (string) $fileId);
            if (null === $storedFile) {
                continue;
            }
            $this->storage->delete($storedFile->getVirtualPath());
            $result->getFiles()->removeElement($storedFile);
            $this->entityManager->remove($storedFile);
        }

        // One original name per result. The form already rejects duplicates for
        // a readable error, this is the invariant for programmatic callers.
        $names = array_flip(array_map(
            static fn (TaskResultFile $stored): string => (string) $stored->getOriginalName(),
            $result->getFiles()->toArray(),
        ));
        foreach ($files as $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFile || !$uploadedFile->isValid()) {
                continue;
            }
            $name = $uploadedFile->getClientOriginalName();
            if (\array_key_exists($name, $names)) {
                throw new \RuntimeException(sprintf('A file with this name is already uploaded: %s.', $name));
            }
            $names[$name] = true;
        }

        $incoming = \count(array_filter(
            $files,
            static fn ($file): bool => $file instanceof UploadedFile && $file->isValid(),
        ));
        if (\count($result->getFiles()) + $incoming > $this->maxFiles) {
            throw new \RuntimeException(sprintf('A task result can contain at most %d files.', $this->maxFiles));
        }

        foreach ($files as $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFile || !$uploadedFile->isValid()) {
                continue;
            }
            $result->getFiles()->add($this->storeFile($task, $result, $uploadedFile));
        }

        $this->entityManager->flush();

        return $result;
    }

    private function storeFile(Task $task, TaskResult $result, UploadedFile $uploadedFile): TaskResultFile
    {
        $extension = $uploadedFile->getClientOriginalExtension();
        $virtualPath = sprintf(
            'tasks/%s/%s.%s',
            $task->getId(),
            (string) Uuid::v7(),
            '' !== $extension ? $extension : 'bin',
        );

        // Read the metadata before handing the upload to the storage adapter:
        // put() moves the temporary file away, and UploadedFile::getMimeType()
        // and getSize() read it from disk.
        $originalName = $uploadedFile->getClientOriginalName();
        $mimeType = $uploadedFile->getMimeType();
        $size = (int) $uploadedFile->getSize();

        $this->storage->put($virtualPath, $uploadedFile);

        $file = new TaskResultFile();
        $file->setResult($result);
        $file->setOriginalName($originalName);
        $file->setVirtualPath($virtualPath);
        $file->setMimeType($mimeType);
        $file->setSize($size);

        return $file;
    }

    private function findFile(TaskResult $result, string $fileId): ?TaskResultFile
    {
        foreach ($result->getFiles() as $file) {
            if ($file->getId()?->toRfc4122() === $fileId) {
                return $file;
            }
        }

        return null;
    }
}