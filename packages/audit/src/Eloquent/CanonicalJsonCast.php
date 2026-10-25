<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Eloquent;

use Efnext\Security\Audit\Exceptions\CanonicalizationFailed;
use Efnext\Security\Audit\Hashing\CanonicalJson;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * Stores metadata exactly as it was hashed (same flags as CanonicalJson), so
 * a float like 1.0 does not come back as the integer 1 and trip the verifier.
 * Reads come back in canonical key order as well: MySQL's JSON type reorders
 * object keys, and callers should see the same array on every driver.
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null>
 */
final class CanonicalJsonCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(is_string($value) ? $value : (string) $value, true, 512, JSON_THROW_ON_ERROR);

            /** @var array<string, mixed> $canonical */
            $canonical = CanonicalJson::roundTrip($decoded);
        } catch (JsonException|CanonicalizationFailed) {
            return null;
        }

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return CanonicalJson::encode(is_array($value) ? $value : []);
    }
}
