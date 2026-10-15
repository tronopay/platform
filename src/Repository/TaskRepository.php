<?php

namespace App\Repository;

use App\Entity\PayoutFailureKind;
use App\Entity\PayoutStatus;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\Worker;
use App\ValueObject\Coordinates;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * All tasks, newest first, for the MCP client listing (mirrors the public
     * GET /api/tasks collection in a non-Hydra shape).
     *
     * @return Task[]
     */
    public function findPage(int $offset, int $limit): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns created tasks whose payment deadline has passed, in ascending id order.
     *
     * @return Task[]
     */
    public function findExpiredPaymentTasksAfter(\DateTimeImmutable $now, ?Uuid $lastId, int $limit): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->andWhere('t.paymentDeadlineAt IS NOT NULL')
            ->andWhere('t.paymentDeadlineAt < :now')
            ->setParameter('status', TaskStatus::Created)
            ->setParameter('now', $now)
            ->orderBy('t.id', 'ASC')
            ->setMaxResults($limit);

        if ($lastId !== null) {
            $qb->andWhere('t.id > :lastId')
                ->setParameter('lastId', $lastId, 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns created tasks that are still inside their payment window, in ascending id order.
     *
     * @return Task[]
     */
    public function findPendingPaymentTasksAfter(\DateTimeImmutable $now, ?Uuid $lastId, int $limit): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->andWhere('t.paymentDeadlineAt IS NULL OR t.paymentDeadlineAt >= :now')
            ->setParameter('status', TaskStatus::Created)
            ->setParameter('now', $now)
            ->orderBy('t.id', 'ASC')
            ->setMaxResults($limit);

        if ($lastId !== null) {
            $qb->andWhere('t.id > :lastId')
                ->setParameter('lastId', $lastId, 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Tasks whose result was accepted or rejected and whose escrow money has
     * not been settled yet, in ascending id order.
     *
     * A task with a payout in a non-settled state (prepared, failed/retryable,
     * abandoned) is still returned: {@see \App\Service\PayoutPreparer} decides
     * whether it must be rebuilt. Tasks are excluded once they have a payout
     * that is settled (broadcast/confirmed) or failed permanently, so a daily
     * run does not rescan history or re-issue a permanent failure.
     *
     * @return Task[]
     */
    public function findTasksForPayoutAfter(?Uuid $lastId, int $limit): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.status IN (:statuses)')
            ->andWhere('NOT EXISTS (SELECT p.id FROM App\Entity\Payout p WHERE p.task = t AND (p.status IN (:settledStatuses) OR (p.status = :failedStatus AND p.failureKind = :permanentKind)))')
            ->setParameter('statuses', [TaskStatus::Accepted->value, TaskStatus::Rejected->value], ArrayParameterType::STRING)
            ->setParameter('settledStatuses', [PayoutStatus::Broadcast->value, PayoutStatus::Confirmed->value], ArrayParameterType::STRING)
            ->setParameter('failedStatus', PayoutStatus::Failed->value)
            ->setParameter('permanentKind', PayoutFailureKind::Permanent->value)
            ->orderBy('t.id', 'ASC')
            ->setMaxResults($limit);

        if ($lastId !== null) {
            $qb->andWhere('t.id > :lastId')
                ->setParameter('lastId', $lastId, 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

/**
     * Tasks that are paid and waiting for an executor ("the pool").
     *
     * @return Task[]
     */
    public function findPool(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->setParameter('status', TaskStatus::Prepaid)
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The pool page, built with native SQL so PostGIS distance functions can be
     * used directly on the geography column. Supports type / radius filters,
     * sorting (newest · price · distance) and offset pagination.
     *
     * $opts keys (all optional):
     *   - type             ?TaskType   filter by task type
     *   - radiusMiles      ?int        filter by distance in miles (needs the worker location)
     *   - includeNoLoc     bool        when a radius is set: also return tasks without a location
     *   - sort             string      'newest'|'price_high'|'price_low'|'nearest'
     *   - page             int         1-based
     *   - per              int         10|20|50
     *
     * @return array{items: Task[], total: int, distances: array<string, ?float>}
     */
    public function findPoolPage(Worker $worker, array $opts = []): array
    {
        $type = $opts['type'] ?? null;
        $radiusMiles = $opts['radiusMiles'] ?? null;
        $includeNoLoc = (bool) ($opts['includeNoLoc'] ?? false);
        $sort = $opts['sort'] ?? 'newest';
        $page = max(1, (int) ($opts['page'] ?? 1));
        $per = in_array((int) ($opts['per'] ?? 10), [10, 20, 50], true) ? (int) ($opts['per'] ?? 10) : 10;

        $origin = Coordinates::tryFromString($worker->getLocation());
        $hasOrigin = null !== $origin;

        // Distance features need the worker location. Without it every task is
        // shown and the radius / nearest-sort predicates are ignored.
        $useRadius = $hasOrigin && null !== $radiusMiles;
        $radiusMeters = null !== $radiusMiles ? (float) $radiusMiles * 1609.344 : null;

        // A concrete geometry point bound via named parameters (the GeoJSON
        // text form trips PostGIS overload resolution for unknown params).
        $originExpr = 'ST_SetSRID(ST_MakePoint(:lng::float8, :lat::float8), 4326)::geography';

        $where = ['t.status = :status'];
        $params = ['status' => TaskStatus::Prepaid->value];

        if (null !== $type) {
            $where[] = 't.type = :type';
            $params['type'] = $type->value;
        }

        if ($useRadius) {
            if ($includeNoLoc) {
                $where[] = '(t.location IS NULL OR ST_DWithin(t.location, '.$originExpr.', :radius))';
            } else {
                $where[] = '(t.location IS NOT NULL AND ST_DWithin(t.location, '.$originExpr.', :radius))';
            }
            $params['lng'] = $origin->longitude;
            $params['lat'] = $origin->latitude;
            $params['radius'] = $radiusMeters;
        }

        // The SELECT also needs the origin point (distance column / nearest
        // ordering) whenever the worker has a location, even without a radius.
        $selectParams = $params;
        if ($hasOrigin && !$useRadius) {
            $selectParams['lng'] = $origin->longitude;
            $selectParams['lat'] = $origin->latitude;
        }

        // The distance is always selected so the template can render it even
        // when sorting by something else; NULL without a worker location or
        // when the task has no coordinates. Rounded to 1 decimal via ::numeric
        // (PostgreSQL has round(numeric, int), not round(double, int)).
        $distanceExpr = $hasOrigin
            ? 'ROUND((ST_Distance(t.location, '.$originExpr.') / 1609.344)::numeric, 1)'
            : 'CAST(NULL AS double precision)';

        $orderBy = match ($sort) {
            'price_high' => 't.price_sun DESC, t.id DESC',
            'price_low' => 't.price_sun ASC, t.id DESC',
            'nearest' => $hasOrigin
                ? '(CASE WHEN t.location IS NULL THEN 1 ELSE 0 END), ST_Distance(t.location, '.$originExpr.') ASC, t.id DESC'
                : 't.created_at DESC, t.id DESC',
            default => 't.created_at DESC, t.id DESC',
        };

        $conn = $this->getEntityManager()->getConnection();

        $countSql = 'SELECT COUNT(*) FROM task t WHERE '.implode(' AND ', $where);
        $total = (int) $conn->fetchOne($countSql, $params);

        $sql = 'SELECT t.id, '.$distanceExpr.' AS distance_miles FROM task t '
            .'WHERE '.implode(' AND ', $where).' ORDER BY '.$orderBy
            .' OFFSET :offset LIMIT :per';

        $pageParams = $selectParams;
        $pageParams['offset'] = ($page - 1) * $per;
        $pageParams['per'] = $per;
        $rows = $conn->fetchAllAssociative($sql, $pageParams);

        $distances = [];
        $ids = [];
        foreach ($rows as $row) {
            $distances[(string) $row['id']] = null !== $row['distance_miles'] ? (float) $row['distance_miles'] : null;
            $ids[] = (string) $row['id'];
        }

        $items = [] === $ids ? [] : $this->hydrateInOrder($ids);

        return ['items' => $items, 'total' => $total, 'distances' => $distances];
    }

    /**
     * Hydrates task entities preserving the id order returned by the query.
     *
     * @param list<string> $ids
     *
     * @return Task[]
     */
    private function hydrateInOrder(array $ids): array
    {
        $entities = $this->createQueryBuilder('t')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', array_map(fn ($id) => Uuid::fromString($id), $ids))
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($entities as $entity) {
            $byId[(string) $entity->getId()] = $entity;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * The single active task of a worker ("assigned"). At most one per worker.
     */
    public function findActiveTask(Worker $worker): ?Task
    {
        $tasks = $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->andWhere('t.assignedTo = :worker')
            ->setParameter('status', TaskStatus::Assigned)
            ->setParameter('worker', $worker)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $tasks[0] ?? null;
    }

    /**
     * One page of the worker's history: tasks the worker already submitted work
     * for (under review, accepted, rejected or finished), newest activity first.
     *
     * @return Task[]
     */
    public function findHistoryForWorker(Worker $worker, int $limit, int $offset): array
    {
        return $this->historyQueryBuilder($worker)
            ->orderBy('t.updatedAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Total number of history entries of the worker (for pagination).
     */
    public function countHistoryForWorker(Worker $worker): int
    {
        return (int) $this->historyQueryBuilder($worker)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The TRX the worker has earned, in sun: the price of every task whose
     * result the client accepted (the money was released to the worker).
     *
     * "finished" is deliberately excluded — it is reachable from both accepted
     * and rejected (docs/proposals.md §1.3), so it cannot prove a payout.
     */
    public function sumEarnedSunForWorker(Worker $worker): int
    {
        $sum = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.priceSun), 0)')
            ->where('t.assignedTo = :worker')
            ->andWhere('t.status = :status')
            ->setParameter('worker', $worker)
            ->setParameter('status', TaskStatus::Accepted)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $sum;
    }

    /**
     * Shared predicate of the history queries: the task is assigned to the
     * worker (kept on processed/accepted/rejected/finished) and has a history
     * status.
     */
    private function historyQueryBuilder(Worker $worker): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->where('t.assignedTo = :worker')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('worker', $worker)
            ->setParameter('statuses', TaskStatus::historyValues(), ArrayParameterType::STRING);
    }

//    /**
//     * @return Task[] Returns an array of Task objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('t')
//            ->andWhere('t.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('t.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Task
//    {
//        return $this->createQueryBuilder('t')
//            ->andWhere('t.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
