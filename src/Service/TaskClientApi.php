<?php

namespace App\Service;

use App\Dto\CreateTaskRequest;
use App\Dto\CreateTaskResponse;
use App\Dto\GetTaskInvoiceResponse;
use App\Dto\GetTaskResultResponse;
use App\Dto\TaskResultFileResponse;
use App\Entity\Task;
use App\Entity\TaskResultFile;
use App\Exception\InvalidTaskTransitionException;
use App\Exception\TaskValidationException;
use App\Helper\TaskPasswordVerifier;
use App\Repository\TaskRepository;
use App\ValueObject\Price;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Shared service for client-side task operations.
 *
 * Both the API-Platform processors/providers and the MCP tools delegate to
 * this service, so business rules stay in one place.
 *
 * Not-found conditions throw {@see NotFoundHttpException}; password failures
 * throw {@see AccessDeniedHttpException}; invalid transitions throw
 * {@see InvalidTaskTransitionException}; validation failures throw
 * {@see TaskValidationException}.
 */
final readonly class TaskClientApi implements TaskClientApiInterface
{
    public function __construct(
        private TaskRepository $tasks,
        private TaskService $taskService,
        private TaskPasswordVerifier $passwordVerifier,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(PAYMENT_WINDOW)%')]
        private string $paymentWindow = '1 hour',
    ) {
    }

    /**
     * Validates the request DTO, creates a Task entity, persists it and returns
     * the invoice/status DTO the existing REST API returns.
     *
     * @throws TaskValidationException when the input fails validation
     */
    public function createTask(CreateTaskRequest $request): CreateTaskResponse
    {
        $this->validateOrFail($request);

        $task = new Task();
        $task->setStatement($request->statement);
        $task->setType($request->type);
        $task->setPriceSun(Price::toSun($request->price));
        $task->setRefundAddress($request->refundAddress);
        $task->setWebhook($request->webhook);
        $task->setLocation($request->getLocation());

        if ('' !== $request->password) {
            $hashedPassword = $this->passwordHasher->hashPassword($task, $request->password);
            $task->setPassword($hashedPassword);
        }

        $task->setPaymentDeadlineAt(
            ($task->getCreatedAt() ?? new \DateTimeImmutable())
                ->add(\DateInterval::createFromDateString($this->paymentWindow)),
        );

        $this->taskService->move($task, 'to_created');

        $address = $task->getWallet()?->getAddress() ?? '';

        return new CreateTaskResponse(
            id: $task->getId(),
            taskStatus: $task->getStatus(),
            price: Price::toPrice($task->getPriceSun()),
            paymentAddress: $address,
            paymentWindow: $this->paymentWindow,
        );
    }

    /**
     * Finds a task by id (public, no password required).
     */
    public function getTask(string $taskId): Task
    {
        if (!Uuid::isValid($taskId)) {
            throw new NotFoundHttpException('Task not found.');
        }

        $task = $this->tasks->find($taskId);
        if (!$task instanceof Task) {
            throw new NotFoundHttpException('Task not found.');
        }

        return $task;
    }

    /**
     * Returns a page of all tasks, newest first.
     *
     * @return array{items: Task[], total: int, page: int, per: int, totalPages: int}
     */
    public function listTasks(int $page = 1, int $per = 30): array
    {
        $page = max(1, $page);
        $per = max(1, min($per, 100));
        $offset = ($page - 1) * $per;

        $items = $this->tasks->findPage($offset, $per);
        $total = $this->tasks->countAll();
        $totalPages = max(1, (int) ceil($total / $per));

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per' => $per,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Cancels (soft-deletes) a task, after verifying the task password.
     *
     * @throws NotFoundHttpException|AccessDeniedHttpException|InvalidTaskTransitionException
     */
    public function cancelTask(string $taskId, string $password): Task
    {
        $task = $this->getTask($taskId);
        $this->passwordVerifier->verifyPlaintext($task, $password);

        try {
            $this->taskService->move($task, 'to_canceled_created');
        } catch (\Exception $e) {
            throw new InvalidTaskTransitionException('The task cannot be cancelled in its current state.', previous: $e);
        }

        return $task;
    }

    /**
     * Accepts a processed task's result, after verifying the task password.
     *
     * @throws NotFoundHttpException|AccessDeniedHttpException|InvalidTaskTransitionException
     */
    public function acceptTask(string $taskId, string $password): Task
    {
        $task = $this->getTask($taskId);
        $this->passwordVerifier->verifyPlaintext($task, $password);

        try {
            $this->taskService->move($task, 'to_accepted');
        } catch (\Exception $e) {
            throw new InvalidTaskTransitionException('The task cannot be accepted in its current state.', previous: $e);
        }

        return $task;
    }

    /**
     * Rejects a processed task's result, after verifying the task password.
     *
     * @throws NotFoundHttpException|AccessDeniedHttpException|InvalidTaskTransitionException
     */
    public function rejectTask(string $taskId, string $password): Task
    {
        $task = $this->getTask($taskId);
        $this->passwordVerifier->verifyPlaintext($task, $password);

        try {
            $this->taskService->move($task, 'to_rejected');
        } catch (\Exception $e) {
            throw new InvalidTaskTransitionException('The task cannot be rejected in its current state.', previous: $e);
        }

        return $task;
    }

    /**
     * Returns the invoice (payment address, amount, status) for a task.
     *
     * @throws NotFoundHttpException|AccessDeniedHttpException
     */
    public function getInvoice(string $taskId, string $password): GetTaskInvoiceResponse
    {
        $task = $this->getTask($taskId);
        $this->passwordVerifier->verifyPlaintext($task, $password);

        return new GetTaskInvoiceResponse(
            id: $task->getId(),
            taskStatus: $task->getStatus(),
            price: Price::toPrice($task->getPriceSun()),
            paymentAddress: $task->getWallet()?->getAddress() ?? '',
            paymentWindow: $this->paymentWindow,
        );
    }

    /**
     * Returns the result (text + file metadata) for a task.
     *
     * @throws NotFoundHttpException|AccessDeniedHttpException
     */
    public function getResult(string $taskId, string $password): GetTaskResultResponse
    {
        $task = $this->getTask($taskId);
        $this->passwordVerifier->verifyPlaintext($task, $password);

        $response = new GetTaskResultResponse();
        $response->id = $task->getId();
        $response->taskStatus = $task->getStatus();
        $response->result = $task->getResult()?->getMessage();

        foreach ($task->getResult()?->getFiles() ?? [] as $file) {
            $response->files[] = new TaskResultFileResponse(
                id: $file->getId(),
                name: (string) $file->getOriginalName(),
                mimeType: (string) $file->getMimeType(),
                size: $file->getSize(),
                url: $this->urlGenerator->generate('app_api_task_result_file', [
                    'id' => (string) $task->getId(),
                    'fileId' => (string) $file->getId(),
                ]),
            );
        }

        return $response;
    }

    /**
     * Finds a single result file (with password + ownership checks).
     *
     * @throws NotFoundHttpException|AccessDeniedHttpException
     */
    public function getResultFile(string $taskId, string $fileId, string $password): TaskResultFile
    {
        $task = $this->getTask($taskId);
        $this->passwordVerifier->verifyPlaintext($task, $password);

        $file = $this->entityManager->getRepository(TaskResultFile::class)->find($fileId);
        if (!$file instanceof TaskResultFile) {
            throw new NotFoundHttpException('File not found.');
        }

        if ((string) $file->getResult()?->getTask()?->getId() !== (string) $task->getId()) {
            throw new NotFoundHttpException('File not found for this task.');
        }

        return $file;
    }

    private function validateOrFail(CreateTaskRequest $request): void
    {
        $violations = [];
        foreach ($this->validator->validate($request) as $violation) {
            $violations[] = sprintf(
                '%s: %s',
                $violation->getPropertyPath() ?: 'input',
                (string) $violation->getMessage(),
            );
        }

        if ([] !== $violations) {
            throw new TaskValidationException($violations);
        }
    }
}