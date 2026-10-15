<?php

namespace App\Tests\ValueObject;

use App\ValueObject\Coordinates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoordinatesTest extends TestCase
{
    public function testParsesLatLngShorthand(): void
    {
        $coordinates = Coordinates::fromLatLng('51.500675, -0.143105');

        self::assertSame(51.500675, $coordinates->latitude);
        self::assertSame(-0.143105, $coordinates->longitude);
    }

    public function testFromStringParsesShorthandWithoutSpace(): void
    {
        $coordinates = Coordinates::fromString('55.7558,37.6173');

        self::assertSame(55.7558, $coordinates->latitude);
        self::assertSame(37.6173, $coordinates->longitude);
    }

    public function testFromStringParsesGeoJsonPoint(): void
    {
        $coordinates = Coordinates::fromString('{"type":"Point","coordinates":[37.6173,55.7558]}');

        self::assertSame(55.7558, $coordinates->latitude);
        self::assertSame(37.6173, $coordinates->longitude);
    }

    public function testToGeoJsonUsesLongitudeLatitudeOrder(): void
    {
        self::assertSame(
            '{"type":"Point","coordinates":[37.6173,55.7558]}',
            (new Coordinates(55.7558, 37.6173))->toGeoJson(),
        );
    }

    public function testNormalizeConvertsShorthandToGeoJson(): void
    {
        self::assertSame(
            '{"type":"Point","coordinates":[37.6173,55.7558]}',
            Coordinates::normalize('55.7558, 37.6173'),
        );
    }

    public function testNormalizeKeepsValidGeoJsonUnchanged(): void
    {
        $geoJson = '{"type":"Point","coordinates":[37.6173,55.7558]}';

        self::assertSame($geoJson, Coordinates::normalize($geoJson));
    }

    public function testNormalizeReturnsUnparseableValueUnchanged(): void
    {
        self::assertSame('not-a-location', Coordinates::normalize('not-a-location'));
    }

    #[DataProvider('validValues')]
    public function testAcceptsValidValues(string $value, float $latitude, float $longitude): void
    {
        $coordinates = Coordinates::tryFromString($value);

        self::assertNotNull($coordinates);
        self::assertSame($latitude, $coordinates->latitude);
        self::assertSame($longitude, $coordinates->longitude);
    }

    public static function validValues(): iterable
    {
        yield 'shorthand' => ['55.7558, 37.6173', 55.7558, 37.6173];
        yield 'shorthand negative' => ['-33.865, 151.209', -33.865, 151.209];
        yield 'geojson' => ['{"type":"Point","coordinates":[37.6173,55.7558]}', 55.7558, 37.6173];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(string $value): void
    {
        self::assertNull(Coordinates::tryFromString($value));
    }

    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'single number' => ['55.7558'];
        yield 'non numeric' => ['foo, bar'];
        yield 'latitude out of range' => ['91.0, 37.0'];
        yield 'longitude out of range' => ['55.0, 181.0'];
        yield 'broken json' => ['{"type":"Point"'];
        yield 'json is not a point' => ['{"type":"LineString","coordinates":[[0,0],[1,1]]}'];
    }

    public function testRejectsOutOfRangeCoordinatesOnConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Coordinates(100.0, 0.0);
    }
}
