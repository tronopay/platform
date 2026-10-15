<?php

namespace App\ValueObject;

/**
 * An immutable WGS84 point.
 *
 * Accepts the shorthand "latitude, longitude" (e.g. "51.500675, -0.143105")
 * as well as a GeoJSON Point, and renders the GeoJSON text expected by the
 * PostGIS "geography" column (GeoJSON coordinates are [longitude, latitude]).
 */
final readonly class Coordinates
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw new \InvalidArgumentException(\sprintf('Latitude must be between -90 and 90, "%s" given.', $latitude));
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            throw new \InvalidArgumentException(\sprintf('Longitude must be between -180 and 180, "%s" given.', $longitude));
        }
    }

    /**
     * Parses the shorthand "latitude, longitude" form.
     */
    public static function fromLatLng(string $value): self
    {
        $parts = array_map('trim', explode(',', $value));

        if (2 !== \count($parts) || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            throw new \InvalidArgumentException(\sprintf('Expected coordinates as "latitude, longitude", "%s" given.', $value));
        }

        return new self((float) $parts[0], (float) $parts[1]);
    }

    /**
     * Parses a GeoJSON Point (coordinates are [longitude, latitude]).
     */
    public static function fromGeoJson(string $value): self
    {
        try {
            $data = json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(\sprintf('Invalid GeoJSON: "%s".', $value), previous: $e);
        }

        $coordinates = \is_array($data) ? ($data['coordinates'] ?? null) : null;

        if ('Point' !== ($data['type'] ?? null) || !\is_array($coordinates) || 2 !== \count($coordinates)) {
            throw new \InvalidArgumentException(\sprintf('Expected a GeoJSON Point, "%s" given.', $value));
        }

        [$longitude, $latitude] = array_values($coordinates);

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            throw new \InvalidArgumentException('GeoJSON Point coordinates must be numeric.');
        }

        return new self((float) $latitude, (float) $longitude);
    }

    /**
     * Accepts either the "latitude, longitude" shorthand or a GeoJSON Point.
     */
    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ('' === $trimmed) {
            throw new \InvalidArgumentException('Coordinates must not be empty.');
        }

        return str_starts_with($trimmed, '{') ? self::fromGeoJson($trimmed) : self::fromLatLng($trimmed);
    }

    public static function tryFromString(?string $value): ?self
    {
        if (null === $value) {
            return null;
        }

        try {
            return self::fromString($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Normalizes any accepted input to the GeoJSON text stored in the database.
     * Unparseable values are returned unchanged so validation can report them.
     */
    public static function normalize(string $value): string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '{')) {
            return null === self::tryFromString($trimmed) ? $value : $trimmed;
        }

        return self::tryFromString($trimmed)?->toGeoJson() ?? $value;
    }

    public function toGeoJson(): string
    {
        return json_encode([
            'type' => 'Point',
            'coordinates' => [$this->longitude, $this->latitude],
        ], \JSON_THROW_ON_ERROR);
    }
}
