<?php

namespace App\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;

#[AsDoctrineListener(Events::onFlush)]
class EntityChangesListener
{
    const CHANGE_INSERT = 'insert';
    const CHANGE_UPDATE = 'update';
    const CHANGE_DELETE = 'delete';

    private array $collections = [];
    private array $entitiesChanges = [];

    public function onFlush(OnFlushEventArgs $eventArgs): void
    {
        $em = $eventArgs->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->addEntityChangeData($uow->getEntityChangeSet($entity), $entity, self::CHANGE_INSERT);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->addEntityChangeData($uow->getEntityChangeSet($entity), $entity, self::CHANGE_UPDATE);
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->addEntityChangeData($uow->getEntityChangeSet($entity), $entity, self::CHANGE_DELETE);
        }

//        foreach ($uow->getScheduledCollectionDeletions() as $col) {
//            $this->addCollectionChangeData($col);
//        }
//
//        foreach ($uow->getScheduledCollectionUpdates() as $col) {
//            $this->addCollectionChangeData($col);
//        }
    }

    /**
     * @return void
     */
    public function clearAllEntityChanges(): void
    {
        $this->entitiesChanges = [];
        $this->collections = [];
    }

    /**
     * @param object $entity
     *
     * @return array
     */
    public function getEntityChanges(object $entity): array
    {
        $class = $this->getClass($entity);
        $key = spl_object_hash($entity);

        return $this->entitiesChanges[$class][$key] ?? [
            'entity' => $entity,
            'type' => '',
            'change_set' => [],
            'relations' => [],
        ];
    }

    /**
     * @param array $excludeClasses
     * @param array $excludeFields
     *
     * @return array
     */
    public function getAllChanges(array $excludeClasses = [], array $excludeFields = ['updatedAt']): array
    {
        $result = [];
        foreach($this->entitiesChanges as $changedClassName => $changedClassRows) {
            if (in_array($changedClassName, $excludeClasses, true)) {
                continue;
            }

            foreach($changedClassRows as $changedEntityData) {
                $entityClass = str_replace('\\', '_', $changedClassName);
                $entityChangesData = $this->getEntityChanges($changedEntityData['entity']);
                $entityChanges = $this->prepareClearedChanges($entityChangesData['change_set'], $excludeFields);
                if ($entityChanges) {
                    $result[] = [$entityClass, $entityChangesData['type'], $entityChanges];
                }
            }
        }

        return $result;
    }

    /**
     * @param object $entity
     *
     * @return string
     */
    public function getClass(object $entity): string
    {
        return str_replace('Proxies\\__CG__\\', '', get_class($entity));
    }

    private function prepareClearedChanges(array $entityChanges, array $excludeFields): array
    {
        $result = [];
        foreach ($entityChanges as $entityField => $entityChange) {
            if (in_array($entityField, $excludeFields)) {
                continue;
            }

            foreach ([0,1] as $key) {
                if ($entityChange[$key] instanceof \DateTimeInterface) {
                    $entityChange[$key] = $entityChange[$key]->format('Y-m-d H:i:s');
                }
                if (is_object($entityChange[$key])) {
                    $relationClass = $this->getClass($entityChange[$key]);
                    $relationClass = str_replace('\\', '_', $relationClass);
                    $entityChange[$key] = $relationClass;
                }
            }
            if ($entityChange[0] === $entityChange[1]) {
                continue;
            }

            $result[$entityField] = $entityChange;
        }

        return $result;
    }

    /**
     * @param array  $changeSet
     * @param object $entity
     * @param string $type
     */
    private function addEntityChangeData(array $changeSet, object $entity, string $type): void
    {
        $class = $this->getClass($entity);

        $this->entitiesChanges[$class][spl_object_hash($entity)] = [
            'type'       => $type,
            'entity'     => $entity,
            'change_set' => $changeSet,
            'relations'  => [],
        ];
    }

    /**
     * @param PersistentCollection $collection
     */
    private function addCollectionChangeData(PersistentCollection $collection)
    {
        $owner = $collection->getOwner();
        $ownerClass = $this->getClass($owner);
        $collectionMapping = $collection->getMapping();
        $mappedField = $collectionMapping['mappedBy'] ?: (
        $collectionMapping['targetEntity'] ? $collectionMapping['fieldName'] : null
        );

        $this->collections[$ownerClass][spl_object_hash($owner)][$mappedField] = [
            'insert' => $collection->getInsertDiff(),
            'delete' => $collection->getDeleteDiff(),
        ];
    }
}
