<?php

namespace App\Entity;

enum TaskType: string
{
    case Mark = 'mark';
    case Verify = 'verify';
    case Classify = 'classify';
    case Compare = 'compare';
    case Photo = 'photo';
    case Video = 'video';
    case Audio ='audio';
    case Location = 'location';
    case Delivery = 'delivery';
    case Other = 'other';

    public function label(): string
    {
        return $this->name;
    }

    public static function forStatusSelect(): array
    {
        return array_combine(
            array_map(fn($enum) => $enum->name, self::cases()),
            array_map(fn($enum) => $enum->value, self::cases()),
        );
    }
}
