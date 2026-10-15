<?php

namespace App\Tests\Validator;

use App\Validator\ValidTronAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ValidTronAddressValidatorTest extends TestCase
{
    public function testAcceptsBase58Address(): void
    {
        self::assertCount(0, $this->validate('TEsUWfRqtr6jmjHuxvrEMVzoSh5Mz52TzT'));
    }

    public function testAllowsNull(): void
    {
        self::assertCount(0, $this->validate(null));
    }

    public function testAllowsBlankValue(): void
    {
        self::assertCount(0, $this->validate(''));
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(mixed $value): void
    {
        $violations = $this->validate($value);

        self::assertCount(1, $violations);
        self::assertSame($value, $violations->get(0)->getInvalidValue());
    }

    public static function invalidValues(): iterable
    {
        yield 'wrong prefix' => ['tQn9Y2khEsLJW1ChVWFMSMeRDow5KcbLSE'];
        yield 'too short' => ['TOoShort'];
        yield 'bad checksum' => ['TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6x'];
        yield 'reserved chars' => ['TQn9Y2khEsLJW1ChVWFMSMeRDow5KcbLSE0'];
    }

    private function validate(mixed $value): \Symfony\Component\Validator\ConstraintViolationListInterface
    {
        return Validation::createValidator()->validate($value, new ValidTronAddress());
    }
}
