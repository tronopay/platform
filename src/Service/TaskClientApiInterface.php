<?php

namespace App\Service;

use App\Dto\CreateTaskRequest;
use App\Dto\CreateTaskResponse;
use App\Dto\GetTaskInvoiceResponse;
use App\Dto\GetTaskResultResponse;
use App\Entity\Task;
use App\Entity\TaskResultFile;

/**
 * Contract for the shared client-side task service. The REST API processors/
 * providers and the MCP tools/resources both consume this interface, so the
 * business rules stay in the single {@see TaskClientApi} implementation.
 */
interface TaskClientApiInterface
{
    /**
     * @throws \App\Exception\TaskValidationException
     */
    public function createTask(CreateTaskRequest $request): CreateTaskResponse;

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function getTask(string $taskId): Task;

    /**
     * @return array{items: Task[], total: int, page: int, per: int, totalPages: int}
     */
    public function listTasks(int $page = 1, int $per = 30): array;

    public function cancelTask(string $taskId, string $password): Task;

    public function acceptTask(string $taskId, string $password): Task;

    public function rejectTask(string $taskId, string $password): Task;

    public function getInvoice(string $taskId, string $password): GetTaskInvoiceResponse;

    public function getResult(string $taskId, string $password): GetTaskResultResponse;

    public function getResultFile(string $taskId, string $fileId, string $password): TaskResultFile;
}
