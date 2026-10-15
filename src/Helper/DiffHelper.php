<?php

namespace App\Helper;

use Doctrine\ORM\PersistentCollection;

class DiffHelper
{
    const FORMAT_ARRAY = 'array';
    const FORMAT_DATE = 'date';
    const FORMAT_DATE_TIME = 'datetime_short';
    const FORMAT_DATE_TIME_FULL = 'datetime';
    const FORMAT_MONEY = 'money';
    const FORMAT_MONEY_COIN = 'money_coin';
    const FORMAT_REMOVE = 'remove';

    /**
     * @param array $changesDiff
     * @param array $fieldsFormat
     *
     * @return array
     */
    public static function format(array $changesDiff, array $fieldsFormat): array
    {
        unset($changesDiff['updatedAt']);

        foreach ($changesDiff as $fieldName => $changes) {
            if ($changes instanceof PersistentCollection || !isset($fieldsFormat[$fieldName])) {
                continue;
            }
            $formatType = $fieldsFormat[$fieldName];

            foreach ([0, 1] as $key) {
                $itemValue = $changes[$key];
                switch ($formatType) {
                    case self::FORMAT_ARRAY:
                        if (is_array($itemValue)) {
                            $changesDiff[$fieldName][$key] = implode(', ', array_values($itemValue));
                        }
                        break;
                    case self::FORMAT_DATE:
                        $changesDiff[$fieldName][$key] = self::formatDateTime($itemValue, 'd.m.Y');
                        break;
                    case self::FORMAT_DATE_TIME:
                        $changesDiff[$fieldName][$key] = self::formatDateTime($itemValue, 'd.m.Y H:i');
                        break;
                    case self::FORMAT_DATE_TIME_FULL:
                        $changesDiff[$fieldName][$key] = self::formatDateTime($itemValue, 'd.m.Y H:i:s');
                        break;
                    case self::FORMAT_MONEY:
                        $changesDiff[$fieldName][$key] = number_format(($itemValue?:0) / 1_000_000, 0, '.', ' ');
                        break;
                    case self::FORMAT_MONEY_COIN:
                        $changesDiff[$fieldName][$key] = number_format(($itemValue?:0) / 1_000_000, 6, '.', ' ');
                        break;
                    case self::FORMAT_REMOVE:
                        if (isset($changesDiff[$fieldName])) {
                            unset($changesDiff[$fieldName]);
                        }
                        break;
                    default:
                        if (is_array($formatType)) {
                            $changesDiff[$fieldName][$key] = $formatType[$itemValue ?? ''] ?? $itemValue;
                        }
                        if (is_callable($formatType)) {
                            $changesDiff[$fieldName][$key] = $formatType($itemValue);
                        }
                }
            }
        }

        return $changesDiff;
    }

    /**
     * @param \DateTimeInterface|null $value
     * @param string                  $dateFormat
     *
     * @return string
     */
    private static function formatDateTime(?\DateTimeInterface $value, string $dateFormat): string
    {
        return $value instanceof \DateTimeInterface ? $value->format($dateFormat) : '';
    }
}
