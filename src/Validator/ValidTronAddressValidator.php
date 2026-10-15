<?php

namespace App\Validator;

use IEXBase\TronAPI\Value\Address;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidTronAddressValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidTronAddress) {
            throw new UnexpectedTypeException($constraint, ValidTronAddress::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value) || !self::isValidTronAddress($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', (string) $value)
                ->addViolation();
        }
    }

    /**
     * A user-facing TRON address is a 34-character Base58Check value starting
     * with "T". The Base58Check checksum is verified by the tron-api library.
     */
    public static function isValidTronAddress(string $address): bool
    {
        if (1 !== preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return false;
        }

        return Address::isValid($address);
    }
}