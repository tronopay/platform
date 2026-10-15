<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class ValidCoordinates extends Constraint
{
    public string $message = 'The value "{{ value }}" is not a valid location. Use "latitude, longitude" (for example "51.500675, -0.143105") or a GeoJSON Point.';
}
