<?php

namespace App\Controller;

use App\Entity\Task;
use App\Entity\TaskResult;
use App\Entity\TaskResultFile;
use App\Entity\TaskStatus;
use App\Entity\TaskType;
use App\Entity\Worker;
use App\Form\TaskResultFormType;
use App\Repository\TaskRepository;
use App\Service\TaskCabinetService;
use App\Service\TaskResultService;
use App\Storage\FileStorageInterface;
use App\ValueObject\Coordinates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Mobile (web) personal cabinet of a task executor.
 *
 * @see ui-spec.md §7
 */
#[Route('/cabinet')]
class CabinetController extends AbstractController
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TaskCabinetService $cabinet,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_cabinet', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $worker = $this->getWorker();

        $type = $this->queryTaskType($request->query->get('type'));
        $radius = $this->queryRadius($request->query->get('radius'));
        $sort = $this->querySort($request->query->get('sort'));
        // Tasks without a location are shown by default, so a worker without a
        // location still sees the whole pool; a radius narrows it down.
        $inclNoLoc = 'hide' !== $request->query->get('incl_noloc', 'show');
        $per = $this->queryPerPage($request->query->get('per'));
        $page = max(1, (int) ($request->query->get('page') ?? 1));

        $activeTask = $this->cabinet->activeTask($worker);

        $opts = [
            'type' => $type,
            'radiusMiles' => $radius,
            'includeNoLoc' => $inclNoLoc,
            'sort' => $sort,
            'per' => $per,
            'page' => $page,
        ];

        $pool = $this->cabinet->poolPage($worker, $opts);

        $totalPages = max(1, (int) ceil($pool['total'] / $per));
        $currentPage = min($page, $totalPages);

        // A hand-typed ?page=N beyond the last page must not render an empty
        // list while the pager claims "Page X of Y" — re-query the last page.
        if ($currentPage !== $page && $pool['total'] > 0) {
            $pool = $this->cabinet->poolPage($worker, [...$opts, 'page' => $currentPage]);
        }

        $items = [];
        foreach ($pool['items'] as $task) {
            $items[] = [
                'task' => $task,
                'distanceMiles' => $pool['distances'][(string) $task->getId()] ?? null,
            ];
        }

        return $this->render('cabinet/index.html.twig', [
            'activeTask' => $activeTask,
            'activeTaskId' => $activeTask?->getId(),
            'pool' => ['items' => $items, 'total' => $pool['total']],
            'taskTypes' => TaskType::cases(),
            'filterState' => [
                'type' => $type,
                'radius' => $radius,
                'incl_noloc' => $inclNoLoc ? 'show' : 'hide',
                'sort' => $sort,
                'per' => $per,
                'page' => $currentPage,
            ],
            'pageData' => [
                'page' => $currentPage,
                'per' => $per,
                'total' => $pool['total'],
                'totalPages' => $totalPages,
            ],
            'workerLocation' => Coordinates::tryFromString($worker->getLocation()),
        ]);
    }

    #[Route('/profile', name: 'app_cabinet_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request): Response
    {
        $worker = $this->getWorker();

        if ($request->isMethod('POST')) {
            $this->checkCsrf('cabinet_profile', $request);

            if ($request->request->has('clear')) {
                $this->cabinet->updateWorkerLocation($worker, null);
                $this->addFlash('success', 'Location cleared.');
            } else {
                $lat = $request->request->get('latitude');
                $lng = $request->request->get('longitude');

                try {
                    if (null === $lat || null === $lng || '' === trim((string) $lat) || '' === trim((string) $lng)) {
                        throw new \InvalidArgumentException('Enter both latitude and longitude.');
                    }
                    $this->cabinet->updateWorkerLocation($worker, new Coordinates((float) $lat, (float) $lng));
                    $this->addFlash('success', 'Location saved.');
                } catch (\InvalidArgumentException $e) {
                    $this->addFlash('danger', 'Invalid coordinates: '.$e->getMessage());
                }
            }

            return $this->redirectToRoute('app_cabinet_profile');
        }

        $coords = Coordinates::tryFromString($worker->getLocation());

        return $this->render('cabinet/profile.html.twig', [
            'activeTaskId' => $this->cabinet->activeTask($worker)?->getId(),
            'latitude' => $coords?->latitude,
            'longitude' => $coords?->longitude,
            'hasLocation' => null !== $coords,
            'locationUpdatedAt' => $worker->getLocationUpdatedAt(),
        ]);
    }

    /**
     * History of the worker's submitted tasks plus the total earned in TRX
     * (accepted tasks only). Closes the open question of ui-spec.md §17.
     */
    #[Route('/history', name: 'app_cabinet_history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        $worker = $this->getWorker();

        // Finalize first: an expired task may just have entered the history,
        // and the page (and its pager) must count it right away.
        $activeTask = $this->cabinet->activeTask($worker);

        $per = $this->queryPerPage($request->query->get('per'));
        $page = max(1, (int) ($request->query->get('page') ?? 1));

        $history = $this->cabinet->historyPage($worker, $page, $per);

        $totalPages = max(1, (int) ceil($history['total'] / $per));
        $currentPage = min($page, $totalPages);

        // Same clamp as the pool: a hand-typed ?page=N beyond the last page
        // shows the last page instead of an empty list with a wrong pager.
        if ($currentPage !== $page && $history['total'] > 0) {
            $history = $this->cabinet->historyPage($worker, $currentPage, $per);
        }

        return $this->render('cabinet/history.html.twig', [
            'activeTaskId' => $activeTask?->getId(),
            'history' => $history,
            'totalEarnedSun' => $this->cabinet->totalEarnedSun($worker),
            'pageData' => [
                'page' => $currentPage,
                'per' => $per,
                'total' => $history['total'],
                'totalPages' => $totalPages,
            ],
        ]);
    }

    /**
     * Silently registers the worker's current device position. Called by the
     * location tracker on page load and before active actions, so the geo-location
     * stays fresh without ever blocking the task flow.
     */
    #[Route('/location', name: 'app_cabinet_location', methods: ['POST'])]
    public function updateLocation(Request $request): Response
    {
        $this->checkCsrf('cabinet_location', $request);

        $lat = $request->request->get('lat');
        $lng = $request->request->get('lng');

        try {
            if (null === $lat || null === $lng || '' === trim((string) $lat) || '' === trim((string) $lng)) {
                throw new \InvalidArgumentException('Both lat and lng are required.');
            }
            $coords = new Coordinates((float) $lat, (float) $lng);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $updated = $this->cabinet->updateWorkerLocation($this->getWorker(), $coords);

        return new JsonResponse(['updated' => $updated]);
    }

    #[Route('/tasks/{id}/assign', name: 'app_cabinet_assign', methods: ['POST'])]
    public function assign(Request $request, string $id): Response
    {
        $task = $this->findTask($id);
        $this->checkCsrf('cabinet_assign_'.(string) $task->getId(), $request);

        try {
            $this->cabinet->assign($this->getWorker(), $task);
            $this->addFlash('success', 'Task taken. You have 2 hours to complete it.');
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet');
    }

    #[Route('/task/{id}', name: 'app_cabinet_task', methods: ['GET'])]
    public function showTask(string $id): Response
    {
        $task = $this->findTask($id);
        $this->cabinet->finalizeTask($task);

        if ($task->getAssignedTo()?->getId() !== $this->getWorker()->getId()) {
            throw new NotFoundHttpException('Task not found.');
        }

        return $this->renderTask($task, $this->createResultForm($task->getResult(), $task));
    }

    #[Route('/task/{id}/result', name: 'app_cabinet_result', methods: ['POST'])]
    public function submitResult(Request $request, string $id, TaskResultService $resultService): Response
    {
        $task = $this->findTask($id);
        $this->cabinet->finalizeTask($task);
        $this->assertAssigned($task);

        $result = $task->getResult() ?? new TaskResult();
        $form = $this->createResultForm($result, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $resultService->save(
                    $task,
                    $this->getWorker(),
                    (string) $form->get('message')->getData(),
                    $form->get('files')->getData() ?? [],
                );
            } catch (\RuntimeException $e) {
                $this->addFlash('danger', $e->getMessage());

                return $this->redirectToRoute('app_cabinet_task', ['id' => (string) $task->getId()]);
            }

            // "Complete task" submits this very form (ui-spec.md §12), so the
            // message the worker typed is always persisted before the
            // irreversible completion instead of being silently discarded.
            if ($request->request->has('save_and_complete')) {
                return $this->completeTask($task);
            }

            $this->addFlash('success', 'Result saved.');

            return $this->redirectToRoute('app_cabinet_task', ['id' => (string) $task->getId()]);
        }

        return $this->renderTask($task, $form, new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Renders the task page with everything the template needs, including the
     * server-side countdown text (visible without JavaScript).
     */
    private function renderTask(Task $task, FormInterface $form, ?Response $response = null): Response
    {
        $remaining = $this->deadlineRemainingSeconds($task);

        return $this->render('cabinet/task.html.twig', [
            'task' => $task,
            'result' => $task->getResult(),
            'resultForm' => $form->createView(),
            'deadlineRemaining' => $remaining,
            'deadlineRemainingText' => null === $remaining ? null : $this->formatDuration($remaining),
            'activeTaskId' => $task->getId(),
        ], $response);
    }

    #[Route('/task/{id}/release', name: 'app_cabinet_release', methods: ['POST'])]
    public function release(Request $request, string $id): Response
    {
        $task = $this->findTask($id);
        $this->checkCsrf('cabinet_release_'.(string) $task->getId(), $request);

        try {
            $this->cabinet->release($this->getWorker(), $task);
            $this->addFlash('info', 'Task returned to the pool.');
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet');
    }

    #[Route('/files/{id}', name: 'app_cabinet_file_download', methods: ['GET'])]
    public function downloadFile(string $id, FileStorageInterface $storage): Response
    {
        try {
            $file = $this->entityManager->getRepository(TaskResultFile::class)->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $file = null;
        }

        // Unknown or malformed id → 404.
        if (!$file instanceof TaskResultFile) {
            throw new NotFoundHttpException('File not found.');
        }

        $task = $file->getResult()?->getTask();
        if (!$task instanceof Task || $task->getAssignedTo()?->getId() !== $this->getWorker()->getId()) {
            throw new AccessDeniedHttpException();
        }

        $response = new Response($storage->get($file->getVirtualPath()));
        $response->headers->set('Content-Type', (string) $file->getMimeType());
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            (string) $file->getOriginalName(),
        ));

        return $response;
    }

    /**
     * Removes a single result file of the worker's active task. Called by the
     * confirmation modal (see templates/cabinet/_confirm_remove_file_modal.html.twig),
     * so removing a file no longer depends on saving the whole form.
     */
    #[Route('/task/{id}/remove-file/{fileId}', name: 'app_cabinet_remove_file', methods: ['POST'])]
    public function removeFile(Request $request, string $id, string $fileId, FileStorageInterface $storage): Response
    {
        $task = $this->findTask($id);
        $this->cabinet->finalizeTask($task);
        $this->assertAssigned($task);

        try {
            $file = $this->entityManager->getRepository(TaskResultFile::class)->find(Uuid::fromString($fileId));
        } catch (\InvalidArgumentException) {
            $file = null;
        }

        $result = $task->getResult();
        $belongsToResult = $file instanceof TaskResultFile
            && null !== $result
            && $result->getFiles()->exists(
                static fn (int $i, TaskResultFile $candidate): bool => null !== $candidate->getId() && $candidate->getId()->equals($file->getId()),
            );
        if (!$file instanceof TaskResultFile || !$belongsToResult) {
            throw new NotFoundHttpException('File not found.');
        }

        $this->checkCsrf('cabinet_remove_file_'.(string) $file->getId(), $request);

        $storage->delete((string) $file->getVirtualPath());
        $result->getFiles()->removeElement($file);
        $this->entityManager->remove($file);
        $this->entityManager->flush();

        $this->addFlash('success', 'File removed.');

        return $this->redirectToRoute('app_cabinet_task', ['id' => (string) $task->getId()]);
    }

    /**
     * Irreversible manual completion, triggered by the "Complete task" button
     * of the result form after that result has been saved.
     */
    private function completeTask(Task $task): Response
    {
        try {
            $this->cabinet->complete($this->getWorker(), $task);
            $this->addFlash('success', 'Task completed and submitted for review.');
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet');
    }

    private function getWorker(): Worker
    {
        $user = $this->getUser();
        if (!$user instanceof Worker) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }

    private function queryTaskType(mixed $value): ?TaskType
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return TaskType::tryFrom((string) $value);
    }

    private function queryRadius(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $radius = (int) $value;

        return in_array($radius, [1, 3, 5, 10, 20, 50], true) ? $radius : null;
    }

    private function querySort(mixed $value): string
    {
        return in_array((string) $value, ['newest', 'price_high', 'price_low', 'nearest'], true)
            ? (string) $value
            : 'newest';
    }

    private function queryPerPage(mixed $value): int
    {
        $per = (int) ($value ?? 10);

        return in_array($per, [10, 20, 50], true) ? $per : 10;
    }

    private function checkCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function assertAssigned(Task $task): void
    {
        if (TaskStatus::Assigned !== $task->getStatus() || $task->getAssignedTo()?->getId() !== $this->getWorker()->getId()) {
            throw new NotFoundHttpException('The task is not assigned to you or is no longer active.');
        }
    }

    private function findTask(string $id): Task
    {
        try {
            $task = $this->tasks->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $task = null;
        }

        if (!$task instanceof Task) {
            throw new NotFoundHttpException('Task not found.');
        }

        return $task;
    }

    private function createResultForm(?TaskResult $result, Task $task): FormInterface
    {
        $existingFileNames = array_map(
            static fn (TaskResultFile $file): string => (string) $file->getOriginalName(),
            $result?->getFiles()->toArray() ?? [],
        );

        return $this->createForm(TaskResultFormType::class, $result ?? new TaskResult(), [
            'existing_file_names' => $existingFileNames,
        ]);
    }

    private function deadlineRemainingSeconds(Task $task): ?int
    {
        $deadline = $task->getExecutionDeadlineAt();
        if (null === $deadline) {
            return null;
        }

        return max(0, $deadline->getTimestamp() - time());
    }

    /**
     * Same `HH:MM:SS` shape the countdown controller renders, used for the
     * no-JavaScript fallback so the text does not jump on hydration.
     */
    private function formatDuration(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}