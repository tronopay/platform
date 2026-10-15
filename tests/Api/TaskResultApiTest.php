<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Task;
use App\Entity\TaskResult;
use App\Entity\TaskResultFile;
use App\Entity\TaskStatus;
use App\Entity\TaskType;
use App\Kernel;
use App\Storage\FileStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Functional tests for GET /api/tasks/{id}/result (ui-spec.md §8) and the
 * result-file download endpoint it links to.
 */
final class TaskResultApiTest extends WebTestCase
{
    private const PASSWORD = 'client-task-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;
    private FileStorageInterface $storage;

    /** @var list<Uuid> Task ids created by the current test case. */
    private array $createdTaskIds = [];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->storage = $container->get(FileStorageInterface::class);

        $this->createdTaskIds = [];

        $this->em->clear();
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            $this->removeUploadedFiles();

            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
        } finally {
            unset($this->client);
            parent::tearDown();
        }
    }

    public function testReturnsResultTextAndFiles(): void
    {
        $task = $this->createTaskWithResult('Storefront was open until 21:00.');

        $this->client->request('GET', '/api/tasks/'.$task->getId().'/result', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_X_TASK_PASSWORD' => self::PASSWORD,
        ]);
        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame((string) $task->getId(), (string) $payload['id']);
        self::assertSame(TaskStatus::Processed->value, $payload['taskStatus']);
        self::assertSame('Storefront was open until 21:00.', $payload['result']);
        self::assertCount(1, $payload['files']);
        self::assertSame('report.txt', $payload['files'][0]['name']);
        self::assertSame('text/plain', $payload['files'][0]['mimeType']);
        self::assertSame(\strlen('Storefront was open.'), $payload['files'][0]['size']);
        self::assertStringContainsString('/result/files/', $payload['files'][0]['url']);

        // The advertised URL downloads the very same content.
        $this->client->request('GET', $payload['files'][0]['url'], server: [
            'HTTP_X_TASK_PASSWORD' => self::PASSWORD,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('Storefront was open.', $this->client->getResponse()->getContent());
    }

    public function testRejectsWrongPassword(): void
    {
        $task = $this->createTaskWithResult('Result.');

        $this->client->request('GET', '/api/tasks/'.$task->getId().'/result', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_X_TASK_PASSWORD' => 'wrong-password',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testFileDownloadOperationIsDocumented(): void
    {
        $this->client->request('GET', '/api/docs.jsonopenapi');

        self::assertResponseIsSuccessful();

        $documentation = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $path = $documentation['paths']['/api/tasks/{id}/result/files/{fileId}'] ?? null;
        self::assertNotNull($path, 'The result file download operation must be documented.');
        self::assertArrayHasKey('get', $path);

        $operation = $path['get'];

        // The raw file is advertised as a binary payload.
        self::assertArrayHasKey('application/octet-stream', $operation['responses']['200']['content']);

        // The password header is part of the contract, not just of the implementation.
        $parameters = array_column($operation['parameters'], null, 'name');
        self::assertArrayHasKey('id', $parameters);
        self::assertArrayHasKey('fileId', $parameters);
        self::assertSame('header', $parameters['X-Task-Password']['in']);
        self::assertTrue($parameters['X-Task-Password']['required']);
    }

    public function testReturnsNotFoundForUnknownTask(): void
    {
        $this->client->request('GET', '/api/tasks/'.Uuid::v7().'/result', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_X_TASK_PASSWORD' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testFileDownloadRejectsForeignFile(): void
    {
        $task = $this->createTaskWithResult('Result.');
        $other = $this->createTaskWithResult('Other result.');

        $foreignFile = $other->getResult()->getFiles()->first();

        $this->client->request('GET', sprintf('/api/tasks/%s/result/files/%s', $task->getId(), $foreignFile->getId()), server: [
            'HTTP_X_TASK_PASSWORD' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Creates a processed task with a result (text + one stored file).
     */
    private function createTaskWithResult(string $message): Task
    {
        $task = new Task();
        $task->setStatement('Verify whether the storefront is currently open.');
        $task->setPassword($this->passwordHasher->hashPassword($task, self::PASSWORD));
        $task->setType(TaskType::Verify);
        $task->setStatus(TaskStatus::Processed);

        $this->em->persist($task);
        $this->em->flush();

        if (null !== $task->getId()) {
            $this->createdTaskIds[] = $task->getId();
        }

        $result = new TaskResult();
        $result->setTask($task);
        $result->setMessage($message);
        $task->setResult($result);

        $contents = 'Storefront was open.';
        $virtualPath = sprintf('tasks/%s/%s.txt', $task->getId(), Uuid::v7());
        $this->storage->put($virtualPath, $contents);

        $file = new TaskResultFile();
        $file->setResult($result);
        $file->setOriginalName('report.txt');
        $file->setVirtualPath($virtualPath);
        $file->setMimeType('text/plain');
        $file->setSize(\strlen($contents));
        $result->getFiles()->add($file);

        $this->em->persist($result);
        $this->em->flush();

        return $task;
    }

    private function removeUploadedFiles(): void
    {
        if (!$this->em->getConnection()->isTransactionActive()) {
            return;
        }

        foreach ($this->createdTaskIds as $taskId) {
            $task = $this->em->find(Task::class, $taskId);
            $directories = [];

            foreach ($task?->getResult()?->getFiles() ?? [] as $file) {
                $virtualPath = (string) $file->getVirtualPath();
                $this->storage->delete($virtualPath);
                $directories[dirname($virtualPath)] = true;
            }

            foreach (array_keys($directories) as $directory) {
                @rmdir($this->uploadsRoot().'/'.$directory);
            }
        }
    }

    private function uploadsRoot(): string
    {
        $uploadsDir = (string) static::getContainer()->getParameter('uploads_dir');
        if ('' !== $uploadsDir && '/' !== $uploadsDir[0]) {
            $uploadsDir = (string) static::getContainer()->getParameter('kernel.project_dir').'/'.$uploadsDir;
        }

        return rtrim($uploadsDir, '/');
    }
}
