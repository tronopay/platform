<?php

namespace App\Service;

use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\Worker;
use App\Message\TaskExecutionDeadlineMessage;
use App\Repository\TaskRepository;
use App\Storage\FileStorageInterface;
use App\ValueObject\Coordinates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Executor-cabinet business logic: picking tasks, one active task at a time,
 * releasing, manual completion and deadline finalization.
 *
 * @see ui-spec.md §7–§8
 */
final readonly class TaskCabinetService
{
    public function __construct(
        private TaskRepository $tasks,
        private TaskService $taskService,
        private TaskDeadlineFinalizer $deadlineFinalizer,
        private LockFactory $lockFactory,
        private MessageBusInterface $bus,
        private FileStorageInterface $storage,
        private EntityManagerInterface $em,
        #[Autowire(param: 'task_execution_deadline_seconds')]
        private int $executionDeadlineSeconds,
    ) {
    }

    /**
     * Tasks paid and waiting for an executor.
     *
     * @return Task[]
     */
    public function pool(): array
    {
        return $this->tasks->findPool();
    }

    /**
     * One page of the pool: filters, sorting and pagination applied.
     * See TaskRepository::findPoolPage() for the $opts contract.
     */
    public function poolPage(Worker $worker, array $opts = []): array
    {
        return $this->tasks->findPoolPage($worker, $opts);
    }

    /**
     * Stores the worker's geo-location (null clears it) and stamps when it
     * changed. Returns true when the stored value actually changed.
     */
    public function updateWorkerLocation(Worker $worker, ?Coordinates $coordinates): bool
    {
        $location = $coordinates?->toGeoJson();

        if ($worker->getLocation() === $location) {
            return false;
        }

        $worker->setLocation($location);
        $worker->setLocationUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return true;
    }

    /**
     * The worker's single active task. Tasks past the 2-hour deadline are
     * finalized lazily here, so correctness never depends on the messenger
     * consumer being up.
     */
    public function activeTask(Worker $worker): ?Task
    {
        if (null !== ($task = $this->tasks->findActiveTask($worker))) {
            $this->deadlineFinalizer->finalizeExpired($task);
        }

        return $this->tasks->findActiveTask($worker);
    }

    /**
     * Exposes lazy deadline finalization (used by task/result page reads).
     */
    public function finalizeTask(Task $task): bool
    {
        return $this->deadlineFinalizer->finalizeExpired($task);
    }

    /**
     * One page of the worker's history: tasks the worker already submitted work
     * for, with the review/payment outcome the worker otherwise never sees.
     *
     * @return array{items: Task[], total: int}
     */
    public function historyPage(Worker $worker, int $page = 1, int $per = 10): array
    {
        $page = max(1, $page);
        $per = in_array($per, [10, 20, 50], true) ? $per : 10;

        return [
            'items' => $this->tasks->findHistoryForWorker($worker, $per, ($page - 1) * $per),
            'total' => $this->tasks->countHistoryForWorker($worker),
        ];
    }

    /**
     * The TRX the worker has earned, in sun (accepted tasks only).
     */
    public function totalEarnedSun(Worker $worker): int
    {
        return $this->tasks->sumEarnedSunForWorker($worker);
    }

    /**
     * @throws \RuntimeException when the task cannot be assigned
     */
    public function assign(Worker $worker, Task $task): void
    {
        $lock = $this->lockFactory->createLock('worker_'.(string) $worker->getId());
        if (!$lock->acquire()) {
            throw new \RuntimeException('Too many parallel requests. Try again.');
        }

        try {
            $this->doAssign($worker, $task);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws \RuntimeException when the task cannot be released
     */
    public function release(Worker $worker, Task $task): void
    {
        if ($task->getAssignedTo()?->getId() !== $worker->getId()) {
            throw new \RuntimeException('This task is not assigned to you.');
        }

        // Returning to the pool must give the next executor a clean task.
        if (null !== ($result = $task->getResult())) {
            foreach ($result->getFiles() as $file) {
                $this->storage->delete($file->getVirtualPath());
            }
        }

        $task->setAssignedTo(null);
        $task->setAssignedAt(null);
        $task->setExecutionDeadlineAt(null);
        $this->moveSafely($task, 'to_prepaid_from_assigned', 'The task cannot be released right now.');
    }

    /**
     * Manual, irreversible completion of the active task.
     *
     * @throws \RuntimeException when the task cannot be completed
     */
    public function complete(Worker $worker, Task $task): void
    {
        if ($task->getAssignedTo()?->getId() !== $worker->getId()) {
            throw new \RuntimeException('This task is not assigned to you.');
        }

        if (null === $task->getResult()) {
            throw new \RuntimeException('Save a result first — completing a task requires a result.');
        }

        $this->moveSafely($task, 'to_processed', 'The task cannot be completed in its current state.');
    }

    private function doAssign(Worker $worker, Task $task): void
    {
        if (TaskStatus::Prepaid !== $task->getStatus()) {
            throw new \RuntimeException('This task is not available for assignment.');
        }

        if (null !== $this->tasks->findActiveTask($worker)) {
            throw new \RuntimeException('You already have an active task. Finish it first.');
        }

        $now = new \DateTimeImmutable();
        $task->setAssignedTo($worker);
        $task->setAssignedAt($now);
        $task->setExecutionDeadlineAt($now->modify(sprintf('+%d seconds', $this->executionDeadlineSeconds)));

        $this->moveSafely($task, 'to_assigned', 'This task is not available for assignment.');

        // 2-hour timer. If the delayed message never runs, the lazy finalizer
        // (activeTask/finalizeTask) still guarantees a correct state.
        $this->bus->dispatch(
            (new Envelope(new TaskExecutionDeadlineMessage($task->getId())))
                ->with(new DelayStamp($this->executionDeadlineSeconds * 1000))
        );
    }

    private function moveSafely(Task $task, string $transition, string $fallbackMessage): void
    {
        try {
            $this->taskService->move($task, $transition);
        } catch (\Exception $e) {
            throw new \RuntimeException($fallbackMessage, 0, $e);
        }
    }
}