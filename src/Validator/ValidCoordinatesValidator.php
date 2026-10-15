<?php

namespace App\Validator;

use App\ValueObject\Coordinates;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidCoordinatesValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCoordinates) {
            throw new UnexpectedTypeException($constraint, ValidCoordinates::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (null !== Coordinates::tryFromString((string) $value)) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ value }}', (string) $value)
            ->addViolation();
    }
}
