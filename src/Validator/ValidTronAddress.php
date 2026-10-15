<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class ValidTronAddress extends Constraint
{
    public string $message = 'The value "{{ value }}" is not a valid TRON address. Expected a 34-character Base58 address starting with "T" (for example "TEsUWfRqtr6jmjHuxvrEMVzoSh5Mz52TzT").';
}
