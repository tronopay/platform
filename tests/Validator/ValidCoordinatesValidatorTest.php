<?php

namespace App\Tests\Validator;

use App\Validator\ValidCoordinates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ValidCoordinatesValidatorTest extends TestCase
{
    public function testAcceptsShorthand(): void
    {
        self::assertCount(0, $this->validate('55.7558, 37.6173'));
    }

    public function testAcceptsGeoJsonPoint(): void
    {
        self::assertCount(0, $this->validate('{"type":"Point","coordinates":[37.6173,55.7558]}'));
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
        yield 'text' => ['not-a-location'];
        yield 'out of range' => ['100.0, 200.0'];
        yield 'only one number' => ['55.7558'];
    }

    private function validate(mixed $value): \Symfony\Component\Validator\ConstraintViolationListInterface
    {
        return Validation::createValidator()->validate($value, new ValidCoordinates());
    }
}
