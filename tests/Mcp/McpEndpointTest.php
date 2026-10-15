<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the MCP endpoint (/mcp), exercised the way a real MCP
 * client would: JSON-RPC over HTTP, then reading the returned content.
 *
 * @see docs/mcp-server-design.md
 */
final class McpEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get('doctrine')->getManager();

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

    // ---------------------------------------------------------------- protocol

    public function testInitializeHandshake(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
        ]]);

        self::assertSame(200, $this->statusCode);
        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame(1, $response['id']);
        self::assertSame('tronopay-mcp', $response['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $response['result']['capabilities']);
        self::assertArrayHasKey('resources', $response['result']['capabilities']);
        self::assertArrayHasKey('prompts', $response['result']['capabilities']);
    }

    public function testUnknownMethodReturnsMethodNotFound(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'does/not/exist']);

        self::assertSame(-32601, $response['error']['code']);
        self::assertSame(7, $response['id']);
    }

    public function testNotificationGetsAcceptedWithNoBody(): void
    {
        $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        self::assertSame(202, $this->statusCode);
    }

    public function testMalformedJsonReturnsParseError(): void
    {
        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{not json');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(-32700, $payload['error']['code']);
    }

    public function testBatchRequestReturnsOneResponsePerRequest(): void
    {
        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ], \JSON_THROW_ON_ERROR));

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertCount(2, $payload);
        self::assertSame(1, $payload[0]['id']);
        self::assertSame(2, $payload[1]['id']);
    }

    // ------------------------------------------------------------------- tools

    public function testToolsListExposesClientTools(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $names = array_column($response['result']['tools'], 'name');

        self::assertContains('create_task', $names);
        self::assertContains('list_tasks', $names);
        self::assertContains('cancel_task', $names);
        self::assertContains('accept_task', $names);
        self::assertContains('reject_task', $names);

        foreach ($response['result']['tools'] as $tool) {
            self::assertArrayHasKey('description', $tool);
            self::assertArrayHasKey('inputSchema', $tool);
            self::assertSame('object', $tool['inputSchema']['type']);
        }
    }

    public function testCreateTaskToolPersistsATask(): void
    {
        $result = $this->callTool('create_task', [
            'statement' => 'Photograph the storefront sign.',
            'password' => 'secret123',
            'type' => 'photo',
            'price' => '42',
        ]);

        self::assertFalse($result['isError']);
        self::assertNotEmpty($result['data']['id']);
        self::assertSame('created', $result['data']['status']);
        self::assertSame('42.000000', $result['data']['price']);
        self::assertNotEmpty($result['data']['paymentAddress']);

        $task = $this->em->find(Task::class, $result['data']['id']);
        self::assertNotNull($task);
        self::assertSame(TaskStatus::Created, $task->getStatus());
        self::assertSame('Photograph the storefront sign.', $task->getStatement());
    }

    public function testCreateTaskToolRejectsInvalidPrice(): void
    {
        // negative price
        $result = $this->callTool('create_task', [
            'statement' => 'Negative price.',
            'password' => 'secret123',
            'price' => '-5',
        ]);
        self::assertTrue($result['isError']);
        self::assertSame(-32003, $result['data']['error']['code']);

        // price below minimum (10 TRX)
        $result = $this->callTool('create_task', [
            'statement' => 'Below minimum.',
            'password' => 'secret123',
            'price' => '5',
        ]);
        self::assertTrue($result['isError']);
        self::assertSame(-32003, $result['data']['error']['code']);
    }

    public function testCreateTaskToolRejectsShortPassword(): void
    {
        $result = $this->callTool('create_task', [
            'statement' => 'Password too short.',
            'password' => 'short',
        ]);

        self::assertTrue($result['isError']);
        self::assertSame(-32003, $result['data']['error']['code']);
    }

    public function testCreateTaskToolRequiresStatement(): void
    {
        $result = $this->callTool('create_task', ['password' => 'secret123']);

        self::assertTrue($result['isError']);
        self::assertSame(-32602, $result['data']['error']['code']);
    }

    public function testUnknownToolReturnsInvalidParams(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'no_such_tool',
            'arguments' => [],
        ]]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function testListTasksToolReturnsCreatedTask(): void
    {
        $created = $this->callTool('create_task', [
            'statement' => 'Listed task.',
            'password' => 'secret123',
        ]);

        $result = $this->callTool('list_tasks', ['per' => 50]);

        self::assertFalse($result['isError']);
        self::assertGreaterThanOrEqual(1, $result['data']['total']);

        $ids = array_column($result['data']['tasks'], 'id');
        self::assertContains($created['data']['id'], $ids);
    }

    public function testCancelTaskToolRequiresTheRightPassword(): void
    {
        $created = $this->callTool('create_task', [
            'statement' => 'Cancel me.',
            'password' => 'secret123',
        ]);
        $taskId = $created['data']['id'];

        $wrong = $this->callTool('cancel_task', ['task_id' => $taskId, 'password' => 'wrongpass']);
        self::assertTrue($wrong['isError']);
        self::assertSame(-32001, $wrong['data']['error']['code']);

        $ok = $this->callTool('cancel_task', ['task_id' => $taskId, 'password' => 'secret123']);
        self::assertFalse($ok['isError']);
        self::assertSame('canceled', $ok['data']['status']);
    }

    public function testAcceptTaskToolRejectsATaskThatIsNotProcessed(): void
    {
        $created = $this->callTool('create_task', [
            'statement' => 'Not ready to accept.',
            'password' => 'secret123',
        ]);

        $result = $this->callTool('accept_task', [
            'task_id' => $created['data']['id'],
            'password' => 'secret123',
        ]);

        self::assertTrue($result['isError']);
        self::assertSame(-32002, $result['data']['error']['code']);
    }

    public function testAcceptAndRejectWorkOnAProcessedTask(): void
    {
        $created = $this->callTool('create_task', [
            'statement' => 'Review my result.',
            'password' => 'secret123',
        ]);
        $taskId = $created['data']['id'];

        $this->markTaskProcessed($taskId);

        $accepted = $this->callTool('accept_task', ['task_id' => $taskId, 'password' => 'secret123']);
        self::assertFalse($accepted['isError']);
        self::assertSame('accepted', $accepted['data']['status']);
    }

    public function testRejectTaskToolMovesAProcessedTaskToRejected(): void
    {
        $created = $this->callTool('create_task', [
            'statement' => 'Reject my result.',
            'password' => 'secret123',
        ]);
        $taskId = $created['data']['id'];

        $this->markTaskProcessed($taskId);

        $rejected = $this->callTool('reject_task', ['task_id' => $taskId, 'password' => 'secret123']);
        self::assertFalse($rejected['isError']);
        self::assertSame('rejected', $rejected['data']['status']);
    }

    public function testUnknownTaskReturnsTaskNotFound(): void
    {
        $result = $this->callTool('cancel_task', [
            'task_id' => '00000000-0000-7000-8000-000000000000',
            'password' => 'secret123',
        ]);

        self::assertTrue($result['isError']);
        self::assertSame(-32000, $result['data']['error']['code']);
    }

    // --------------------------------------------------------------- resources

    public function testResourceTemplatesAreAdvertised(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/templates/list']);

        $templates = array_column($response['result']['resourceTemplates'], 'uriTemplate');

        self::assertContains('task://{id}', $templates);
        self::assertContains('task://{id}/invoice?password={password}', $templates);
        self::assertContains('task://{id}/result?password={password}', $templates);
        self::assertContains('task://{id}/result/files/{fileId}?password={password}', $templates);
    }

    public function testReadTaskResourceNeedsNoPassword(): void
    {
        $created = $this->callTool('create_task', [
            'statement' => 'Readable task.',
            'password' => 'secret123',
        ]);
        $taskId = $created['data']['id'];

        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => [
            'uri' => 'task://'.$taskId,
        ]]);

        $contents = $response['result']['contents'];
        self::assertCount(1, $contents);
        self::assertSame('application/json', $contents[0]['mimeType']);

        $task = json_decode($contents[0]['text'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($taskId, $task['id']);
        self::assertSame('Readable task.', $task['statement']);
    }

    public function testReadInvoiceResourceChecksThePassword(): void
    {
        $created = $this->callTool('create_task', [
            'statement' => 'Invoice task.',
            'password' => 'secret123',
            'price' => '15',
        ]);
        $taskId = $created['data']['id'];

        $ok = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => [
            'uri' => 'task://'.$taskId.'/invoice?password=secret123',
        ]]);
        $invoice = json_decode($ok['result']['contents'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('15.000000', $invoice['price']);
        self::assertNotEmpty($invoice['paymentAddress']);

        $bad = $this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => [
            'uri' => 'task://'.$taskId.'/invoice?password=nope',
        ]]);
        self::assertSame(-32001, $bad['error']['code']);
    }

    public function testReadResultResourceReturnsEmptyResultBeforeSubmission(): void
    {
        $created = $this->callTool('create_task', [
            'statement' => 'No result yet.',
            'password' => 'secret123',
        ]);
        $taskId = $created['data']['id'];

        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => [
            'uri' => 'task://'.$taskId.'/result?password=secret123',
        ]]);

        $result = json_decode($response['result']['contents'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($taskId, $result['id']);
        self::assertNull($result['result']);
        self::assertSame([], $result['files']);
    }

    public function testReadUnknownResourceReturnsError(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => [
            'uri' => 'task://not-a-uuid',
        ]]);

        self::assertSame(-32602, $response['error']['code']);
    }

    // ----------------------------------------------------------------- prompts

    public function testPromptsListExposesTemplates(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/list']);

        $names = array_column($response['result']['prompts'], 'name');
        self::assertContains('create_task', $names);
        self::assertContains('check_task', $names);
    }

    public function testGetPromptRendersArguments(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/get', 'params' => [
            'name' => 'create_task',
            'arguments' => ['description' => 'Photograph a sign', 'budget_trx' => '50'],
        ]]);

        $text = $response['result']['messages'][0]['content']['text'];
        self::assertStringContainsString('Photograph a sign', $text);
        self::assertStringContainsString('50', $text);
    }

    public function testGetUnknownPromptReturnsInvalidParams(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/get', 'params' => [
            'name' => 'nope',
        ]]);

        self::assertSame(-32602, $response['error']['code']);
    }

    // ----------------------------------------------------------------- helpers

    private int $statusCode = 0;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode($payload, \JSON_THROW_ON_ERROR));

        $this->statusCode = $this->client->getResponse()->getStatusCode();

        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Calls a tool and decodes the JSON text payload it returns.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{isError: bool, data: array<string, mixed>}
     */
    private function callTool(string $name, array $arguments): array
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => $name,
            'arguments' => $arguments,
        ]]);

        self::assertArrayHasKey('result', $response, 'Tool call must not be a JSON-RPC error: '.json_encode($response));

        $result = $response['result'];
        $data = json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);

        return ['isError' => (bool) $result['isError'], 'data' => $data];
    }

    /**
     * Moves a freshly created task straight to "processed" so the accept/reject
     * paths can be exercised without a full worker round-trip.
     */
    private function markTaskProcessed(string $taskId): void
    {
        $task = $this->em->find(Task::class, $taskId);
        self::assertNotNull($task);

        $task->setStatus(TaskStatus::Processed);
        $this->em->flush();
    }
}
