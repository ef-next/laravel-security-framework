<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Hashing;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Efnext\Security\Audit\Exceptions\CanonicalizationFailed;
use JsonException;
use JsonSerializable;
use Stringable;

/**
 * Deterministic JSON for hashing: keys sorted recursively (byte order),
 * lists kept in order, no escaping of slashes or unicode, zero fractions
 * preserved. Encoding failures throw instead of degrading to an empty string.
 *
 * Framework-free on purpose.
 */
final class CanonicalJson
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    /**
     * @param  array<array-key, mixed>  $value
     */
    public static function encode(array $value): string
    {
        try {
            return json_encode(self::normalize($value), self::FLAGS);
        } catch (JsonException $e) {
            throw CanonicalizationFailed::because($e->getMessage());
        }
    }

    /**
     * Round-trip a value through JSON so hashing sees exactly what the
     * database will give back later (objects become arrays, ints stay ints,
     * floats keep their fraction).
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function roundTrip(array $value): array
    {
        try {
            /** @var array<array-key, mixed> $decoded */
            $decoded = json_decode(json_encode(self::normalize($value), self::FLAGS), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw CanonicalizationFailed::because($e->getMessage());
        }

        return $decoded;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            if ($value instanceof JsonSerializable) {
                return self::normalize($value->jsonSerialize());
            }

            if ($value instanceof BackedEnum) {
                return $value->value;
            }

            if ($value instanceof DateTimeInterface) {
                return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            }

            if ($value instanceof Stringable) {
                return (string) $value;
            }

            throw CanonicalizationFailed::because('objects of type '.$value::class.' are not supported in audit records.');
        }

        if (is_resource($value)) {
            throw CanonicalizationFailed::because('resources are not supported in audit records.');
        }

        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            throw CanonicalizationFailed::because('NaN and infinite floats are not supported in audit records.');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = self::normalize($item);
        }

        ksort($normalized, SORT_STRING);

        // An empty associative array would encode as [] – acceptable, but keep
        // objects that became empty distinguishable from lists at the schema level.
        return $normalized;
    }
}
