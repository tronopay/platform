<?php

namespace App\Service;

use App\Helper\DiffHelper;
use App\Listener\EntityChangesListener;

readonly class EntityChangesService
{
    public function __construct(
        private EntityChangesListener $entityChangesListener,
    ) {}

    /**
     * @param object $entity
     * @param array $diffFieldsFormat
     *
     * @return array
     */
    public function getChanges(object $entity, array $diffFieldsFormat = []): array
    {
        $changes = $this->entityChangesListener->getEntityChanges($entity);
        $isAdd = ($changes['type'] ?? EntityChangesListener::CHANGE_UPDATE) === EntityChangesListener::CHANGE_INSERT;

        $changesDiff = $this->prepareDiffItem($changes, $diffFieldsFormat);

        foreach ($changes['relations'] as $subChanges) {
            $fieldName = $subChanges['field'];
            $isMarkedToRemove = ($diffFieldsFormat[$fieldName] ?? null) === DiffHelper::FORMAT_REMOVE;
            if ($fieldName === 'comments' || $isMarkedToRemove) {
                continue;
            }
            $isNeedWrite = !isset($changesDiff[$fieldName]) || is_array($changesDiff[$fieldName]);
            $subEntity = $subChanges['entity'];
            $subEntityTitle = $this->entityChangesListener->getClass($subEntity);

            switch ($subChanges['type']) {
                case 'insert':
                    $value = [
                        'system_info' => 'added',
                    ];
                    break;
                case 'delete':
                    $value = [
                        'system_info' => 'removed',
                    ];
                    break;
                case 'update':
                default:
                    $value = $this->prepareDiffItem($subChanges, $diffFieldsFormat[$fieldName] ?? []);
                    $isNeedWrite = $isNeedWrite && !empty($value);
                    break;
            }

            if ($isNeedWrite) {
                if (!isset($changesDiff[$fieldName]) || !is_array($changesDiff[$fieldName])) {
                    $changesDiff[$fieldName] = [];
                }
                $changesDiff[$fieldName][$subEntityTitle] = $value;
            }
        }

        return [$isAdd, $changesDiff];
    }

    private function prepareDiffItem(array $changes, array $diffFieldsFormat): array
    {
        $changesPrepared = DiffHelper::format($changes['change_set'], $diffFieldsFormat);

        return $this->prepareTextEntityDiffArray($changesPrepared);
    }

    private function prepareTextEntityDiffArray(array $changes = []): array
    {
        foreach ($changes as $field => $data) {
            $fieldChanges = $this->prepareTextEntityRowDiff($data, $field);
            if ($fieldChanges) {
                $changes[$field] = $fieldChanges;
            } else {
                unset($changes[$field]);
            }
        }

        return $changes;
    }

    private function prepareTextEntityRowDiff(array $data = [], $fieldName = null): string
    {
        // boolean to string
        if (is_bool($data[0]) && is_bool($data[1])) {
            $data[0] = $data[0] ? 'Да' : 'Нет';
            $data[1] = $data[1] ? 'Да' : 'Нет';
        }
        // array to string
        if (is_array($data[0]) || is_array($data[1])) {
            for ($i = 0; $i < 2; $i++) {
                if (is_array($data[$i])) {
                    $data[$i] = json_encode($data[$i], JSON_UNESCAPED_UNICODE);
                }
            }
        }
        // date to string
        if ($data[0] instanceof \DateTimeInterface || $data[1] instanceof \DateTimeInterface) {
            for ($i = 0; $i < 2; $i++) {
                if ($data[$i] instanceof \DateTimeInterface) {
                    $data[$i] = $data[$i]->format('d.m.Y H:i:s');
                }
            }
        }

        // to string
        if (is_object($data[0]) || is_object($data[1])) {
            for ($i = 0; $i < 2; $i++) {
                if ($data[$i] instanceof \Stringable) {
                    $data[$i] = (string)$data[$i];
                }
            }
        }

        // same value
        if ($data[0] == $data[1]) {
            return false;
        }

        return $this->getStringWithDiffTags($data);
    }

    private function getStringWithDiffTags(array $data): string
    {
        return $this->addTagRemoved($data[0]) . ' -&gt; ' . $this->addTagAdded($data[1]);
    }

    private function addTagRemoved(?string $string): string
    {
        return '<del>' . (string)$string . '</del>';
    }

    private function addTagAdded(?string $string): string
    {
        return '<ins>' . (string)$string . '</ins>';
    }
}
