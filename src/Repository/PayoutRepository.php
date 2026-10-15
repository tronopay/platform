<?php

namespace App\Repository;

use App\Entity\Payout;
use App\Entity\PayoutStatus;
use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payout>
 */
class PayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payout::class);
    }

    public function findOneByTask(Task $task): ?Payout
    {
        return $this->findOneBy(['task' => $task]);
    }

    /**
     * @return Payout[]
     */
    public function findByBatchId(string $batchId): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.batchId = :batchId')
            ->setParameter('batchId', $batchId)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rows for the operator report, newest first.
     *
     * @return Payout[]
     */
    public function findForReport(?PayoutStatus $status = null, ?string $batchId = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(max(1, $limit));

        if ($status !== null) {
            $qb->andWhere('p.status = :status')
                ->setParameter('status', $status->value);
        }

        if ($batchId !== null) {
            $qb->andWhere('p.batchId = :batchId')
                ->setParameter('batchId', $batchId);
        }

        return $qb->getQuery()->getResult();
    }
}
