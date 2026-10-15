<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Task;
use App\Entity\TaskResult;
use App\Entity\TaskStatus;
use App\Entity\TaskType;
use App\Entity\Worker;
use App\Kernel;
use App\Service\TaskCabinetService;
use App\Service\TaskService;
use App\Storage\FileStorageInterface;
use App\ValueObject\Coordinates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Functional tests for the executor cabinet (ui-spec.md §14).
 *
 * Note: the test database must contain the full schema, including the new
 * "worker", "task_result", "task_result_file" tables and the new Task columns
 * (assigned_to_id, assigned_at, execution_deadline_at, result_id).
 * Run your migrations for the test environment once before this suite.
 */
final class WorkerCabinetTest extends WebTestCase
{
    private const WALLET_1 = 'TEsUWfRqtr6jmjHuxvrEMVzoSh5Mz52TzT';
    private const WALLET_2 = 'THXLfBg1Xps3wYxyR73YmyjeTPYsBsquiY';
    private const PASSWORD = 'worker-password-123';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;
    private TaskCabinetService $cabinet;
    private TaskService $taskService;
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
        // Keep the same kernel (and therefore the same DBAL connection) across
        // all requests of a test case. The default per-request reboot closes
        // the connection and silently drops the transaction started below,
        // which would make the data created here invisible to the application.
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->cabinet = $container->get(TaskCabinetService::class);
        $this->taskService = $container->get(TaskService::class);
        $this->storage = $container->get(FileStorageInterface::class);

        $this->createdTaskIds = [];

        $this->em->clear();
        // Isolate each test case in a transaction (nothing leaks into the DB).
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            // Files are written to disk, so a database rollback alone does not
            // remove them. Delete them while the transaction is still active.
            $this->removeUploadedFiles();

            // A request may already have ended the transaction, so only roll
            // back when there is still one active.
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
        } finally {
            unset($this->client);

            // Always shut the kernel down, otherwise the next test case fails
            // with "Booting the kernel before calling createClient() ...".
            parent::tearDown();
        }
    }

    /**
     * Deletes the files the application stored for the tasks created by the
     * current test case. The files must be removed before the rollback hides
     * their TaskResultFile rows.
     */
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

            // The storage deletes files only, so drop the per-task directory
            // it created once it is empty.
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

    public function testCabinetRequiresLogin(): void
    {
        $this->client->request('GET', '/cabinet');

        self::assertResponseRedirects('/login');
    }

    public function testRegisterAndLogin(): void
    {
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('register_submit')->form();
        $form['worker_registration_form[tronAddress]'] = self::WALLET_1;
        $form['worker_registration_form[plainPassword]'] = self::PASSWORD;
        $this->client->submit($form);

        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Account created', $crawler->html());

        $worker = $this->em->getRepository(Worker::class)->findOneBy(['tronAddress' => self::WALLET_1]);
        self::assertNotNull($worker);
        self::assertNotEmpty($worker->getPassword());
        self::assertNotSame(self::PASSWORD, $worker->getPassword());

        $crawler = $this->client->request('GET', '/login');
        $loginForm = $crawler->selectButton('login_submit')->form();
        $loginForm['_username'] = self::WALLET_1;
        $loginForm['_password'] = self::PASSWORD;
        $this->client->submit($loginForm);

        self::assertResponseRedirects('/cabinet');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testWorkerTakesOnePrepaidTask(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task = $this->createTask();
        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($task->getStatement(), $crawler->html());

        $form = $crawler->selectButton('Take the task')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/cabinet');

        $this->em->clear();
        $updated = $this->em->find(Task::class, $task->getId());
        self::assertSame(TaskStatus::Assigned, $updated->getStatus());
        self::assertNotNull($updated->getAssignedAt());
        self::assertNotNull($updated->getExecutionDeadlineAt());
        self::assertSame((string) $worker->getId(), (string) $updated->getAssignedTo()?->getId());
    }

    private function createWorker(string $address): Worker
    {
        $worker = new Worker();
        $worker->setTronAddress($address);
        $worker->setPassword($this->passwordHasher->hashPassword($worker, self::PASSWORD));

        $this->em->persist($worker);
        $this->em->flush();

        return $worker;
    }

    /**
     * Authenticates the browser via the real login form (mirrors the user flow
     * and avoids KernelBrowser::loginUser() session quirks with open transactions).
     */
    private function login(string $address): void
    {
        $crawler = $this->client->request('GET', '/login');
        $csrf = $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');

        $this->client->request('POST', '/login', [
            '_username' => $address,
            '_password' => self::PASSWORD,
            '_csrf_token' => $csrf,
        ]);

        self::assertResponseRedirects('/cabinet');
        $this->client->followRedirect();
    }

    private function createTask(): Task
    {
        return $this->createTaskWith(TaskType::Verify, 100, null);
    }

    private function createTaskWith(?TaskType $type, int $priceSun, ?Coordinates $coords): Task
    {
        $task = new Task();
        // Unique statement per task so pool-order assertions can tell them apart.
        $task->setStatement(sprintf('Verify the storefront — %s task, %d.', $type?->value ?? 'other', $priceSun));
        $task->setPassword(self::PASSWORD);
        $task->setType($type ?? TaskType::Verify);
        $task->setStatus(TaskStatus::Prepaid);
        $task->getWallet()->setAddress(self::WALLET_2);
        $task->setPriceSun($priceSun);
        $task->setLocation($coords?->toGeoJson());

        $this->em->persist($task);
        $this->em->flush();

        if (null !== $task->getId()) {
            $this->createdTaskIds[] = $task->getId();
        }

        return $task;
    }

    private function assignTask(Worker $worker): Task
    {
        $task = $this->createTask();
        $this->cabinet->assign($worker, $task);

        return $task;
    }

    private function saveResult(Task $task): void
    {
        $result = $task->getResult();
        if (null === $result) {
            $result = new TaskResult();
            $result->setTask($task);
            $task->setResult($result);
            $this->em->persist($result);
        }

        $result->setMessage('Verified: the shop is open.');
        $result->setSubmittedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function testWorkerCannotTakeSecondTask(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task1 = $this->createTask();
        $task2 = $this->createTask();
        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet');
        // Both tasks are in the pool, so target the first one explicitly
        // instead of relying on the rendering order of the "Take the task" buttons.
        $form = $crawler->filter(sprintf('form[action$="/cabinet/tasks/%s/assign"]', $task1->getId()))->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/cabinet');

        // CSRF tokens are session-bound, so reuse the one rendered for the
        // second task instead of generating a token outside a request.
        $token = $crawler->filter(sprintf('form[action$="/cabinet/tasks/%s/assign"] input[name="_token"]', $task2->getId()))->attr('value');
        $this->client->request('POST', '/cabinet/tasks/'.$task2->getId().'/assign', [
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/cabinet');

        $this->em->clear();
        self::assertSame(TaskStatus::Assigned, $this->em->find(Task::class, $task1->getId())->getStatus());
        self::assertSame(TaskStatus::Prepaid, $this->em->find(Task::class, $task2->getId())->getStatus());
    }

    public function testResultRequiresMessage(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task = $this->assignTask($worker);
        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet/task/'.$task->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('save_result')->form();
        $form['task_result_form[message]'] = '';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The result message is required', $this->client->getResponse()->getContent());
    }

    /**
     * Uploading a file whose name is already attached to the result must be a
     * readable validation error — the old checkbox choices used to crash the
     * page with a 500 once two same-name files existed — and the typed message
     * must survive the error re-render (ui-spec.md §12).
     */
    public function testUploadingDuplicateFileNameShowsValidationError(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task = $this->assignTask($worker);
        $this->login(self::WALLET_1);

        $tmpFile = sprintf('%s/dup_name.txt', sys_get_temp_dir());

        try {
            // First upload succeeds and lands on the task page.
            $this->submitResultWithFile($task, $tmpFile, 'First upload, keep this.');

            // The same original name picked again is rejected with 422 and a
            // readable message, never a 500.
            $this->submitResultWithFile($task, $tmpFile, 'Second save attempt.');
            self::assertResponseStatusCodeSame(422);
            $content = $this->client->getResponse()->getContent();
            self::assertStringContainsString('already uploaded', $content);

            // The typed message is preserved on the error re-render.
            self::assertStringContainsString('Second save attempt.', $content);

            // And the result still holds exactly one file.
            $this->em->clear();
            $result = $this->em->find(Task::class, $task->getId())->getResult();
            self::assertNotNull($result);
            self::assertCount(1, $result->getFiles());
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Files are removed one at a time: the danger button opens a confirmation
     * modal, whose form POSTs to the removal route and deletes the file from
     * the disk and the result — no "Save result" round-trip involved.
     */
    public function testRemoveFileViaModalConfirmation(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task = $this->assignTask($worker);
        $this->login(self::WALLET_1);

        $tmpFile = sprintf('%s/tronopay_to_remove_%s.txt', sys_get_temp_dir(), uniqid());

        try {
            $this->submitResultWithFile($task, $tmpFile, 'Attached, then removed.');
            $this->client->followRedirect();
            self::assertResponseIsSuccessful();

            $crawler = $this->client->request('GET', '/cabinet/task/'.$task->getId());
            $button = $crawler->filter('button[data-bs-target="#removeFileModal"][aria-label="Remove '.basename($tmpFile).'"]');
            self::assertCount(1, $button);

            // A single shared modal per task, prepared from the button's data
            // attributes by the file-remove Stimulus controller.
            self::assertCount(1, $crawler->filter('#removeFileModal[data-controller="file-remove"]'));

            $file = $this->em->find(Task::class, $task->getId())->getResult()->getFiles()->first();
            $virtualPath = (string) $file->getVirtualPath();

            // Submit the modal form the way Bootstrap does after the worker
            // clicks "Remove": the action and CSRF token come from the button.
            $this->client->request('POST', (string) $button->attr('data-file-remove-url'), [
                '_token' => (string) $button->attr('data-file-remove-token'),
            ]);
            self::assertResponseRedirects('/cabinet/task/'.$task->getId());
            $this->client->followRedirect();
            self::assertStringContainsString('File removed.', $this->client->getResponse()->getContent());

            $this->em->clear();
            $result = $this->em->find(Task::class, $task->getId())->getResult();
            self::assertCount(0, $result->getFiles());
            self::assertFalse($this->storage->exists($virtualPath));
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Submits the result form with a single file and the given message. The
     * temp file must exist at call time (the previous submission moved it).
     */
    private function submitResultWithFile(Task $task, string $tmpFile, string $message): void
    {
        file_put_contents($tmpFile, $message);

        $crawler = $this->client->request('GET', '/cabinet/task/'.$task->getId());
        $token = $crawler->selectButton('save_result')->form()['task_result_form[_token]']->getValue();

        $this->client->request('POST', '/cabinet/task/'.$task->getId().'/result', [
            'task_result_form' => ['_token' => $token, 'message' => $message],
        ], [
            'task_result_form' => ['files' => [new UploadedFile($tmpFile, basename($tmpFile), 'text/plain', null, true)]],
        ]);
    }

    public function testTaskPageExposesAccessibleCountdownAndLogout(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task = $this->assignTask($worker);
        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet/task/'.$task->getId());
        self::assertResponseIsSuccessful();

        // The icon-only logout button carries an explicit accessible name.
        $logout = $crawler->filter('form[action*="logout"] button[aria-label="Log out"]');
        self::assertCount(1, $logout);

        // The countdown is announced as a timer, is not live-region spam, and
        // carries the absolute deadline for assistive technology.
        $timer = $crawler->filter('[data-controller="countdown"]');
        self::assertCount(1, $timer);
        self::assertSame('timer', $timer->attr('role'));
        self::assertSame('off', $timer->attr('aria-live'));
        self::assertStringContainsString('Deadline', $timer->filter('.visually-hidden')->text());

        // The server renders an initial HH:MM:SS value as a no-JS fallback.
        $value = $timer->filter('[data-countdown-target="value"]')->text();
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $value);
    }

    public function testCabinetHasNoSiteNavbarAndPersistentTabBar(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $this->login(self::WALLET_1);

        // Pool page, no active task: the landing navbar is gone and the tab
        // bar is always visible with Tasks + History + Profile (My task appears
        // only once the worker holds an assigned task).
        $crawler = $this->client->request('GET', '/cabinet');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('nav.navbar')->count());
        self::assertStringNotContainsString('For AI agents', $crawler->text());
        self::assertStringNotContainsString('For humans', $crawler->text());

        $bar = $crawler->filter('nav[aria-label="Cabinet"]');
        self::assertCount(1, $bar);
        self::assertCount(1, $bar->filter('a[href="/cabinet"]'));
        self::assertCount(1, $bar->filter('a[href="/cabinet/history"]'));
        self::assertCount(1, $bar->filter('a[href="/cabinet/profile"]'));
        self::assertCount(0, $bar->filter('a:contains("My task")'));
        // The current screen is marked inside the bar (and in the header the
        // profile icon moved away — it is a tab now).
        self::assertCount(1, $bar->filter('a[aria-current="page"][href="/cabinet"]'));
        self::assertCount(0, $crawler->filter('a[aria-label="Profile"]'));
        // Regression guard: the bar sits inside a full-window flex column, so
        // on short pages it stays pinned to the bottom of the window instead
        // of floating right after the content.
        self::assertCount(1, $crawler->filter('div.d-flex.flex-column nav[aria-label="Cabinet"]'));

        // Profile page: same bar, Tasks tab reachable, Profile highlighted.
        $crawler = $this->client->request('GET', '/cabinet/profile');
        self::assertResponseIsSuccessful();
        $bar = $crawler->filter('nav[aria-label="Cabinet"]');
        self::assertCount(1, $bar->filter('a[href="/cabinet"]'));
        self::assertCount(1, $bar->filter('a[aria-current="page"][href="/cabinet/profile"]'));

        // History page: reachable from the bar, Tasks still one tap away, and
        // the History tab is the highlighted one.
        $crawler = $this->client->request('GET', '/cabinet/history');
        self::assertResponseIsSuccessful();
        $bar = $crawler->filter('nav[aria-label="Cabinet"]');
        self::assertCount(1, $bar->filter('a[aria-current="page"][href="/cabinet/history"]'));
        self::assertCount(1, $bar->filter('a[href="/cabinet"]'));

        // With an assigned task the bar grows a fourth tab pointing at it, and
        // the task page itself keeps the bar with My task highlighted.
        // Re-fetch the worker: the HTTP requests above cleared the entity
        // manager, so the previously created instance is detached.
        $worker = $this->em->getRepository(Worker::class)->findOneBy(['tronAddress' => self::WALLET_1]);
        self::assertNotNull($worker);
        $task = $this->assignTask($worker);
        $taskUrl = '/cabinet/task/'.$task->getId();

        $crawler = $this->client->request('GET', '/cabinet');
        $bar = $crawler->filter('nav[aria-label="Cabinet"]');
        self::assertCount(1, $bar->filter('a[href="'.$taskUrl.'"]'));

        $crawler = $this->client->request('GET', $taskUrl);
        self::assertResponseIsSuccessful();
        $bar = $crawler->filter('nav[aria-label="Cabinet"]');
        self::assertCount(1, $bar->filter('a[aria-current="page"][href="'.$taskUrl.'"]'));
    }

    public function testSubmitResultWithFiles(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task = $this->assignTask($worker);
        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet/task/'.$task->getId());
        $form = $crawler->selectButton('save_result')->form();
        $token = $form['task_result_form[_token]']->getValue();

        $tmpFile = sprintf('%s/tronopay_result_%s.txt', sys_get_temp_dir(), uniqid());
        file_put_contents($tmpFile, 'Storefront was open.');

        try {
            // FileBag turns a plain path into an UploadedFile whose isValid()
            // returns false, so hand the form a real (test) UploadedFile.
            $uploadedFile = new UploadedFile($tmpFile, basename($tmpFile), 'text/plain', null, true);

            $this->client->request('POST', '/cabinet/task/'.$task->getId().'/result', [
                'task_result_form' => [
                    '_token' => $token,
                    'message' => 'Verified: the shop is open daily from 9:00 to 21:00.',
                ],
            ], [
                'task_result_form' => ['files' => [$uploadedFile]],
            ]);
        } finally {
            @unlink($tmpFile);
        }

        self::assertResponseRedirects('/cabinet/task/'.$task->getId());

        $this->em->clear();
        $updated = $this->em->find(Task::class, $task->getId());
        self::assertNotNull($updated->getResult());
        self::assertSame('Verified: the shop is open daily from 9:00 to 21:00.', $updated->getResult()->getMessage());
        self::assertCount(1, $updated->getResult()->getFiles());
        self::assertSame(basename($tmpFile), $updated->getResult()->getFiles()->first()->getOriginalName());

        // Removal is an icon-only danger button with a spelled-out accessible
        // name; the old removal checkboxes and their hint are gone.
        $crawler = $this->client->request('GET', '/cabinet/task/'.$task->getId());
        $removeButton = $crawler->filter('button[aria-label="Remove '.basename($tmpFile).'"]');
        self::assertCount(1, $removeButton);
        self::assertStringContainsString('btn-outline-danger', (string) $removeButton->attr('class'));
        self::assertSame('#removeFileModal', $removeButton->attr('data-bs-target'));
        self::assertCount(0, $crawler->filter('input[name="task_result_form[removeFiles][]"]'));
        self::assertStringNotContainsString('Tick the checkbox', $crawler->filter('body')->text());
    }

    public function testManualCompletionIsIrreversible(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task = $this->assignTask($worker);
        $this->saveResult($task);
        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet/task/'.$task->getId());
        $form = $crawler->selectButton('save_and_complete')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/cabinet');

        $this->em->clear();
        self::assertSame(TaskStatus::Processed, $this->em->find(Task::class, $task->getId())->getStatus());

        // The task is gone from the cabinet: the result is read-only now.
        $crawler = $this->client->request('GET', '/cabinet/task/'.$task->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Submitted result', $crawler->html());
        self::assertStringNotContainsString('Complete task', $crawler->html());

        // A second completion attempt is refused and the state stays "processed".
        $this->client->request('POST', '/cabinet/task/'.$task->getId().'/result', [
            'task_result_form' => [
                '_token' => $form['task_result_form[_token]']->getValue(),
                'message' => 'Trying to edit an already completed task.',
            ],
            'save_and_complete' => '1',
        ]);
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        self::assertSame(TaskStatus::Processed, $this->em->find(Task::class, $task->getId())->getStatus());
    }

    /**
     * Regression test for the cabinet UX review (§3.1): "Complete task" used to
     * post a form of its own, so the message typed into the textarea was
     * silently discarded. It now submits the result form itself.
     */
    public function testCompleteTaskSavesTheTypedMessage(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task = $this->assignTask($worker);
        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet/task/'.$task->getId());
        self::assertResponseIsSuccessful();

        $typed = 'Typed on the task page, never pressed "Save result" before completing.';
        $form = $crawler->selectButton('save_and_complete')->form();
        $form['task_result_form[message]'] = $typed;
        $this->client->submit($form);

        self::assertResponseRedirects('/cabinet');

        $this->em->clear();
        $updated = $this->em->find(Task::class, $task->getId());
        self::assertSame(TaskStatus::Processed, $updated->getStatus());
        self::assertNotNull($updated->getResult());
        self::assertSame($typed, $updated->getResult()->getMessage());
    }

    public function testCompleteTaskWithoutMessageKeepsTheTaskAssigned(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task = $this->assignTask($worker);
        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet/task/'.$task->getId());
        $form = $crawler->selectButton('save_and_complete')->form();
        $form['task_result_form[message]'] = '';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The result message is required', $this->client->getResponse()->getContent());

        $this->em->clear();
        $updated = $this->em->find(Task::class, $task->getId());
        self::assertSame(TaskStatus::Assigned, $updated->getStatus());
        self::assertNull($updated->getResult());
    }

    public function testReleaseReturnsTaskToPool(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task = $this->assignTask($worker);
        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet/task/'.$task->getId());
        $token = $crawler->filter(sprintf('form[action$="/cabinet/task/%s/release"] input[name="_token"]', $task->getId()))->attr('value');

        $this->client->request('POST', '/cabinet/task/'.$task->getId().'/release', [
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/cabinet');

        $this->em->clear();
        $updated = $this->em->find(Task::class, $task->getId());
        self::assertSame(TaskStatus::Prepaid, $updated->getStatus());
        self::assertNull($updated->getAssignedTo());
        self::assertNull($updated->getAssignedAt());
        self::assertNull($updated->getExecutionDeadlineAt());
    }

    public function testWorkerCannotTouchForeignTask(): void
    {
        $owner = $this->createWorker(self::WALLET_1);
        $task = $this->assignTask($owner);

        $intruder = $this->createWorker(self::WALLET_2);
        $this->login(self::WALLET_2);

        $this->client->request('GET', '/cabinet/task/'.$task->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAutoFinalizationReturnsToPoolWithoutResult(): void
    {
        $worker = $this->createWorker(self::WALLET_1);
        $task = $this->assignTask($worker);
        $this->login(self::WALLET_1);

        $task = $this->em->find(Task::class, $task->getId());
        $task->setExecutionDeadlineAt((new \DateTimeImmutable())->modify('-1 minute'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/cabinet');
        self::assertResponseIsSuccessful();
        // The expired task is no longer "active" — it is visible again in the pool.
        self::assertStringContainsString($task->getStatement(), $crawler->html());

        $this->em->clear();
        $updated = $this->em->find(Task::class, $task->getId());
        self::assertSame(TaskStatus::Prepaid, $updated->getStatus());
        self::assertNull($updated->getAssignedTo());
        self::assertNull($updated->getExecutionDeadlineAt());
    }

    // ------------------------------------------------------------------
    // Worker history and total earned TRX (ui-spec.md §18).
    // ------------------------------------------------------------------

    public function testHistoryRequiresLogin(): void
    {
        $this->client->request('GET', '/cabinet/history');

        self::assertResponseRedirects('/login');
    }

    public function testHistoryEmptyStateAndZeroTotal(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet/history');
        self::assertResponseIsSuccessful();
        self::assertSame('0.000000', $crawler->filter('[data-history-total]')->text());
        self::assertStringContainsString('No completed tasks yet', $crawler->html());
        self::assertCount(1, $crawler->filter('a[aria-current="page"][href="/cabinet/history"]'));
    }

    /**
     * The history shows every task the worker submitted (under review, accepted
     * and rejected), but only an accepted task — the one whose money was
     * released — counts towards "total earned".
     */
    public function testHistoryListsSubmittedTasksAndSumsOnlyAcceptedTrx(): void
    {
        $worker = $this->createWorker(self::WALLET_1);

        // Under review: listed, not paid yet.
        $underReview = $this->assignTaskWithPrice($worker, 100);
        $this->submitAndComplete($worker, $underReview);

        // Accepted: money released to the worker → the only earned amount.
        $accepted = $this->assignTaskWithPrice($worker, 2_500_000);
        $this->submitAndComplete($worker, $accepted);
        $this->taskService->move($accepted, 'to_accepted');

        // Rejected: listed, never earned.
        $rejected = $this->assignTaskWithPrice($worker, 999);
        $this->submitAndComplete($worker, $rejected);
        $this->taskService->move($rejected, 'to_rejected');

        // A task that expired without a result goes back to the pool, is cleared
        // of its executor and must not appear in the history.
        $expired = $this->assignTaskWithPrice($worker, 500);
        $expired->setExecutionDeadlineAt((new \DateTimeImmutable())->modify('-1 minute'));
        $this->em->flush();

        // A foreign accepted task must never leak into this worker's history.
        $intruder = $this->createWorker(self::WALLET_2);
        $foreign = $this->assignTaskWithPrice($intruder, 7_000_000);
        $this->submitAndComplete($intruder, $foreign);
        $this->taskService->move($foreign, 'to_accepted');

        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet/history');
        self::assertResponseIsSuccessful();

        $html = $crawler->html();
        self::assertStringContainsString($underReview->getStatement(), $html);
        self::assertStringContainsString($accepted->getStatement(), $html);
        self::assertStringContainsString($rejected->getStatement(), $html);
        self::assertStringNotContainsString($expired->getStatement(), $html);
        self::assertStringNotContainsString($foreign->getStatement(), $html);

        // Executor-facing labels, not the raw workflow values.
        self::assertStringContainsString('Under review', $html);
        self::assertStringContainsString('Rejected', $html);

        // Exactly the accepted price: the under-review (0.000100) and rejected
        // (0.000999) tasks are excluded, the foreign 7 TRX task too.
        self::assertSame('2.500000', $crawler->filter('[data-history-total]')->text());
        self::assertStringContainsString('3 submitted', $html);
    }

    public function testHistoryPaginatesAndClampsOutOfRangePage(): void
    {
        $worker = $this->createWorker(self::WALLET_1);

        // 11 entries so the default 10-per-page view has a second page. They are
        // created before login(): the login requests clear the entity manager,
        // which would leave $worker detached and unusable in createHistoryTask().
        for ($i = 0; $i < 11; ++$i) {
            $this->createHistoryTask($worker, TaskStatus::Accepted, 100 + $i);
        }

        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet/history?per=10&page=1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('11 submitted', $crawler->html());
        self::assertStringContainsString('Page 1 of 2', $crawler->html());

        // A hand-typed page beyond the last one shows the last page.
        $crawler = $this->client->request('GET', '/cabinet/history?per=10&page=99');
        self::assertStringContainsString('Page 2 of 2', $crawler->html());

        // The pager keeps the page size.
        $next = $crawler->filter('nav[aria-label="History pages"] a')->last();
        self::assertStringContainsString('per=10', (string) $next->attr('href'));
    }

    private function assignTaskWithPrice(Worker $worker, int $priceSun): Task
    {
        $task = $this->createTaskWith(TaskType::Verify, $priceSun, null);
        $this->cabinet->assign($worker, $task);

        return $task;
    }

    private function submitAndComplete(Worker $worker, Task $task): void
    {
        $this->saveResult($task);
        $this->cabinet->complete($worker, $task);
    }

    /**
     * Creates a task already in a history status, owned by the worker, without
     * walking the whole assign/submit flow (used for pagination).
     */
    private function createHistoryTask(Worker $worker, TaskStatus $status, int $priceSun): Task
    {
        $task = $this->createTaskWith(TaskType::Verify, $priceSun, null);
        $task->setStatus($status);
        $task->setAssignedTo($worker);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    // ------------------------------------------------------------------
    // Worker geo-location (profile page, background endpoint) & pool
    // filters / sorting / pagination (ui-spec.md §15).
    // ------------------------------------------------------------------

    private function giveLocation(string $wallet, Coordinates $coords): void
    {
        $worker = $this->em->getRepository(Worker::class)->findOneBy(['tronAddress' => $wallet]);
        self::assertNotNull($worker);
        $worker->setLocation($coords->toGeoJson());
        $worker->setLocationUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function profileCsrf(): string
    {
        $crawler = $this->client->request('GET', '/cabinet/profile');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('form[action$="/cabinet/profile"] input[name="_token"]')->first()->attr('value');
    }

    private function locationCsrf(): string
    {
        $crawler = $this->client->request('GET', '/cabinet');

        return (string) $crawler->filter('[data-location-tracker-token-value]')->attr('data-location-tracker-token-value');
    }

    public function testProfilePageShowsLocationEditor(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->login(self::WALLET_1);

        $crawler = $this->client->request('GET', '/cabinet/profile');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Use my device location', $crawler->html());
        self::assertCount(1, $crawler->filter('input[name="latitude"]'));
        self::assertCount(1, $crawler->filter('input[name="longitude"]'));
        self::assertStringContainsString('not set', $crawler->html());
    }

    public function testProfileSavesLocationAndPoolUsesIt(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->login(self::WALLET_1);

        $this->client->request('POST', '/cabinet/profile', [
            '_token' => $this->profileCsrf(),
            'latitude' => '55.755826',
            'longitude' => '37.6173',
        ]);
        self::assertResponseRedirects('/cabinet/profile');
        $this->client->followRedirect();
        self::assertStringContainsString('Location saved.', $this->client->getResponse()->getContent());

        $this->em->clear();
        $worker = $this->em->getRepository(Worker::class)->findOneBy(['tronAddress' => self::WALLET_1]);
        $coords = Coordinates::tryFromString($worker?->getLocation());
        self::assertNotNull($coords);
        self::assertEqualsWithDelta(55.755826, $coords->latitude, 0.000001);
        self::assertEqualsWithDelta(37.6173, $coords->longitude, 0.000001);
        self::assertNotNull($worker->getLocationUpdatedAt());

        // The cabinet no longer nags about the missing location and the
        // distance select is enabled.
        $crawler = $this->client->request('GET', '/cabinet');
        self::assertStringNotContainsString('Set your location', $crawler->html());
        self::assertCount(0, $crawler->filter('select[name="radius"][disabled]'));
    }

    public function testProfileRejectsOutOfRangeCoordinates(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->login(self::WALLET_1);

        $this->client->request('POST', '/cabinet/profile', [
            '_token' => $this->profileCsrf(),
            'latitude' => '999',
            'longitude' => '37.6173',
        ]);
        self::assertResponseRedirects('/cabinet/profile');
        $this->client->followRedirect();
        self::assertStringContainsString('Invalid coordinates', $this->client->getResponse()->getContent());

        $this->em->clear();
        $worker = $this->em->getRepository(Worker::class)->findOneBy(['tronAddress' => self::WALLET_1]);
        self::assertNull($worker->getLocation());
    }

    public function testProfileClearsLocation(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->giveLocation(self::WALLET_1, new Coordinates(55.75, 37.61));
        $this->login(self::WALLET_1);

        $this->client->request('POST', '/cabinet/profile', [
            '_token' => $this->profileCsrf(),
            'clear' => '1',
        ]);
        self::assertResponseRedirects('/cabinet/profile');
        $this->client->followRedirect();
        self::assertStringContainsString('Location cleared.', $this->client->getResponse()->getContent());

        $this->em->clear();
        $worker = $this->em->getRepository(Worker::class)->findOneBy(['tronAddress' => self::WALLET_1]);
        self::assertNull($worker->getLocation());
    }

    public function testLocationEndpointSavesPositionAsJson(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->login(self::WALLET_1);

        $this->client->request('POST', '/cabinet/location', [
            '_token' => $this->locationCsrf(),
            'lat' => '48.8566',
            'lng' => '2.3522',
        ]);
        self::assertResponseIsSuccessful();
        $json = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($json['updated']);

        $this->em->clear();
        $worker = $this->em->getRepository(Worker::class)->findOneBy(['tronAddress' => self::WALLET_1]);
        $coords = Coordinates::tryFromString($worker?->getLocation());
        self::assertNotNull($coords);
        self::assertEqualsWithDelta(48.8566, $coords->latitude, 0.000001);
        self::assertEqualsWithDelta(2.3522, $coords->longitude, 0.000001);
    }

    public function testLocationEndpointRequiresCsrfAndValidValues(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->login(self::WALLET_1);

        $this->client->request('POST', '/cabinet/location', ['lat' => '48.85', 'lng' => '2.35']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/cabinet/location', [
            '_token' => $this->locationCsrf(),
            'lat' => '999',
            'lng' => '2.35',
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * The pool page: worker at 55.751244, 37.618423 (Moscow); tasks at
     * ≈0.7 mi, ≈1.5 mi, ≈16 mi away plus one without coordinates.
     *
     * Expected with the default "show tasks without location":
     *   radius=3  → near + mid + the coordinate-less task
     *   radius=50 → all four
     *   incl_noloc=hide drops the coordinate-less task.
     */
    public function testPoolRadiusFilterUsesRealDistance(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->giveLocation(self::WALLET_1, new Coordinates(55.751244, 37.618423));
        $this->login(self::WALLET_1);

        $near = $this->createTaskWith(TaskType::Verify, 500, new Coordinates(55.75, 37.60));
        $mid = $this->createTaskWith(TaskType::Verify, 200, new Coordinates(55.760, 37.655));
        $far = $this->createTaskWith(TaskType::Photo, 900, new Coordinates(55.80, 38.00));
        $noLocation = $this->createTaskWith(TaskType::Photo, 100, null);

        $crawler = $this->client->request('GET', '/cabinet?radius=3');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($near->getStatement(), $crawler->html());
        self::assertStringContainsString($mid->getStatement(), $crawler->html());
        self::assertStringNotContainsString($far->getStatement(), $crawler->html());
        // Without coordinates the task distances do not apply, so it is kept.
        self::assertStringContainsString($noLocation->getStatement(), $crawler->html());
        self::assertStringContainsString('3 in the pool', $crawler->html());
        // The card shows the computed distance in miles, not meters.
        self::assertStringContainsString('mi', $crawler->html());

        // A wide radius brings the far task back, and the coordinate-less task
        // stays visible under the default "show".
        $crawler = $this->client->request('GET', '/cabinet?radius=50');
        self::assertStringContainsString($far->getStatement(), $crawler->html());
        self::assertStringContainsString('4 in the pool', $crawler->html());

        $crawler = $this->client->request('GET', '/cabinet?radius=3&incl_noloc=hide');
        self::assertStringNotContainsString($noLocation->getStatement(), $crawler->html());
        self::assertStringContainsString('2 in the pool', $crawler->html());
        self::assertCount(2, $crawler->filter('form[action*="/assign"]'));
    }

    public function testPoolIgnoresRadiusWithoutWorkerLocation(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->login(self::WALLET_1);
        $this->createTask();

        $crawler = $this->client->request('GET', '/cabinet?radius=3');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Set your location', $crawler->html());
        self::assertCount(1, $crawler->filter('form[action*="/assign"]'));
        self::assertCount(1, $crawler->filter('select[name="radius"][disabled]'));
    }

    public function testPoolSortsByPriceAndDistance(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->giveLocation(self::WALLET_1, new Coordinates(55.751244, 37.618423));
        $this->login(self::WALLET_1);

        $cheapNear = $this->createTaskWith(TaskType::Verify, 100, new Coordinates(55.75, 37.60));
        $priceyMid = $this->createTaskWith(TaskType::Verify, 900, new Coordinates(55.76, 37.70));
        $noLocation = $this->createTaskWith(TaskType::Photo, 500, null);

        $orderedStatements = static function (string $html) use ($cheapNear, $priceyMid, $noLocation): array {
            $positions = [
                $cheapNear->getStatement() => strpos($html, $cheapNear->getStatement()),
                $priceyMid->getStatement() => strpos($html, $priceyMid->getStatement()),
                $noLocation->getStatement() => strpos($html, $noLocation->getStatement()),
            ];
            asort($positions);

            return array_keys($positions);
        };

        $crawler = $this->client->request('GET', '/cabinet?sort=price_high');
        self::assertSame([$priceyMid->getStatement(), $noLocation->getStatement(), $cheapNear->getStatement()], $orderedStatements($crawler->html()));

        $crawler = $this->client->request('GET', '/cabinet?sort=price_low');
        self::assertSame([$cheapNear->getStatement(), $noLocation->getStatement(), $priceyMid->getStatement()], $orderedStatements($crawler->html()));

        // Nearest first; tasks without a location always sort last.
        $crawler = $this->client->request('GET', '/cabinet?sort=nearest');
        self::assertSame([$cheapNear->getStatement(), $priceyMid->getStatement(), $noLocation->getStatement()], $orderedStatements($crawler->html()));
    }

    public function testPoolPaginationKeepsFiltersInUrl(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->login(self::WALLET_1);

        // 11 tasks so the default 10-per-page view has a second page.
        for ($i = 0; $i < 11; ++$i) {
            $this->createTaskWith(TaskType::Verify, 100 + $i, null);
        }

        $crawler = $this->client->request('GET', '/cabinet?per=10&page=1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('11 in the pool', $crawler->html());
        self::assertCount(10, $crawler->filter('form[action*="/assign"]'));
        self::assertStringContainsString('Page 1 of 2', $crawler->html());

        // The "next" link preserves the page-size filter.
        $next = $crawler->filter('a[href*="per=10"]')->last();
        self::assertStringContainsString('page=2', (string) $next->attr('href'));

        $this->client->request('GET', '/cabinet?per=10&page=2');
        self::assertCount(1, $this->client->getCrawler()->filter('form[action*="/assign"]'));
    }

    /**
     * A hand-typed ?page=N beyond the last page shows the last page (with its
     * items), not an empty list while the pager claims "Page 2 of 2".
     */
    public function testPoolClampsOutOfRangePage(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->login(self::WALLET_1);

        for ($i = 0; $i < 11; ++$i) {
            $this->createTaskWith(TaskType::Verify, 100 + $i, null);
        }

        $crawler = $this->client->request('GET', '/cabinet?per=10&page=99');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Page 2 of 2', $crawler->html());
        self::assertCount(1, $crawler->filter('form[action*="/assign"]'));
    }

    public function testPoolTypeFilter(): void
    {
        $this->createWorker(self::WALLET_1);
        $this->login(self::WALLET_1);

        $verify = $this->createTaskWith(TaskType::Verify, 100, null);
        $this->createTaskWith(TaskType::Photo, 200, null);

        $crawler = $this->client->request('GET', '/cabinet?type=verify');
        self::assertStringContainsString($verify->getStatement(), $crawler->html());
        self::assertStringContainsString('1 in the pool', $crawler->html());
        self::assertCount(1, $crawler->filter('form[action*="/assign"]'));

        // Unknown type values fall back to "all types" instead of erroring.
        $crawler = $this->client->request('GET', '/cabinet?type=bogus');
        self::assertCount(2, $crawler->filter('form[action*="/assign"]'));
    }
}
