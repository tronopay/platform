<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Listener\EntityChangesListener;

/**
 * Deterministic double for EntityChangesListener: reports exactly the change
 * set that is fed to it, so EntityChangesService can be tested in isolation.
 */
final class FakeEntityChangesListener extends EntityChangesListener
{
    /** @var array{type: string, change_set: array, relations: array} */
    private array $changes = [
        'type' => EntityChangesListener::CHANGE_UPDATE,
        'change_set' => [],
        'relations' => [],
    ];

    public function __construct()
    {
    }

    /**
     * @param array $changeSet
     */
    public function setChanges(array $changeSet): void
    {
        $this->changes['change_set'] = $changeSet;
    }

    public function setChangeType(string $type): void
    {
        $this->changes['type'] = $type;
    }

    public function getEntityChanges(object $entity): array
    {
        return $this->changes;
    }
}
