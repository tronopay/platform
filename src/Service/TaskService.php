<?php

namespace App\Service;

use App\Entity\Task;
use App\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;


readonly class TaskService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntityChangesService   $entityChangesService,
        private WalletService          $walletService,
        #[Target('task_status')]
        private WorkflowInterface      $workflow,
    )
    {
    }

    public function getEm(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    public function move(Task $task, string $transition): array
    {
        if (!$this->workflow->can($task, $transition)) {
            throw new \Exception($transition . ' - transition unavailable');
        }

        $this->workflow->apply($task, $transition);

        return $this->save($task);
    }

    public function save(Task $task): array
    {
        $now = \DateTimeImmutable::createFromMutable(new \DateTime('now'));
        $task->setUpdatedAt($now);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        if (!$task->getWallet()?->getAddress()) {
            $address = $this->walletService->makeAddress($task->getWallet()->getIndex());
            $task->getWallet()->setAddress($address);

            $this->entityManager->persist($task);
            $this->entityManager->flush();
        }

        [$isAdd, $changes] = $this->entityChangesService->getChanges($task, $task->diffFormat());

        if (!empty($changes)) {
            $comment = $task->getOrCreateCurrentCommentsObject();
            $comment->setChanges($changes);

            $this->entityManager->persist($comment);
            $this->entityManager->flush();
        }

        return $changes;
    }

}
