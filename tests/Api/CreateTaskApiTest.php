<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional test for POST /api/tasks (the task creation flow advertised on
 * the landing page). Regression guard for the 500 caused by DateTimeImmutable
 * reaching EntityChangesService::addTagAdded().
 */
final class CreateTaskApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> Task ids created by the current test case. */
    private array $createdTaskIds = [];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get('doctrine')->getManager();

        $this->createdTaskIds = [];

        $this->em->clear();
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
        } finally {
            unset($this->client);
            parent::tearDown();
        }
    }

    public function testCreateTaskReturnsCreatedStatus(): void
    {
        $this->client->request('POST', '/api/tasks', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/ld+json',
        ], content: json_encode([
            'type' => 'other',
            'statement' => 'Verify storefront operating hours.',
            'password' => '12345678',
            'price' => '10.000000',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertNotEmpty($payload['id']);
        self::assertSame('created', $payload['taskStatus']);
        self::assertSame('10.000000', $payload['price']);
        self::assertNotEmpty($payload['paymentAddress']);
        self::assertStringContainsString('Please deposit 10.000000 TRX', $payload['hint']);

        $this->createdTaskIds[] = (string) $payload['id'];
    }

    /**
     * The response above goes through TaskService::save(), which builds an
     * audit TaskComment. Without the DateTimeImmutable fix the request would
     * have failed with a 500 before producing a response.
     */
    public function testCreateTaskIsPersistedWithAuditComment(): void
    {
        $this->client->request('POST', '/api/tasks', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/ld+json',
        ], content: json_encode([
            'type' => 'other',
            'statement' => 'Verify storefront operating hours.',
            'password' => '12345678',
            'price' => '10.000000',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $taskId = (string) $payload['id'];
        $this->createdTaskIds[] = $taskId;

        $task = $this->em->find(\App\Entity\Task::class, $taskId);
        self::assertNotNull($task, 'The created task must be persisted.');
        self::assertSame(\App\Entity\TaskStatus::Created, $task->getStatus());
        self::assertNotEmpty($task->getWallet()?->getAddress(), 'The escrow wallet address must be derived.');

        $comment = $task->getComments()->first();
        self::assertNotFalse($comment, 'The audit comment must be written.');
        self::assertIsArray($comment->getChanges());
    }
}
