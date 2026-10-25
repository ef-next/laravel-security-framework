<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Sanitizing;

use BackedEnum;
use DateTimeInterface;
use Efnext\Security\Audit\Contracts\MetadataSanitizer;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Redacts secrets by key and by value shape, pseudonymises identifiers with a
 * keyed hash, and bounds depth, key count and string length. Every removal
 * leaves a marker so the log shows that something was there.
 *
 * Framework-free on purpose.
 */
final class DefaultMetadataSanitizer implements MetadataSanitizer
{
    private readonly KeyMatcher $redact;

    private readonly KeyMatcher $pseudonymize;

    private readonly SecretDetector $secrets;

    public function __construct(
        private readonly SanitizerConfig $config,
    ) {
        $this->redact = new KeyMatcher($config->redactKeyPatterns, $config->allowKeys);
        $this->pseudonymize = new KeyMatcher($config->pseudonymizeKeyPatterns, $config->allowKeys);
        $this->secrets = new SecretDetector;
    }

    public function sanitize(array $metadata): array
    {
        /** @var array<string, mixed> $clean */
        $clean = $this->sanitizeAssoc($metadata, 1);

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeAssoc(array $data, int $depth): array
    {
        $out = [];
        $count = 0;

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (++$count > $this->config->maxKeys) {
                $out['_truncated'] = true;
                break;
            }

            if ($this->redact->matches($key)) {
                $out[$key] = $this->config->marker;

                continue;
            }

            if (is_string($value) && $this->pseudonymize->matches($key)) {
                $out[$key.'_hash'] = $this->pseudonym($value);

                continue;
            }

            $out[$key] = $this->sanitizeValue($value, $depth);
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $data
     * @return list<mixed>
     */
    private function sanitizeList(array $data, int $depth): array
    {
        $out = [];

        foreach (array_values($data) as $index => $value) {
            if ($index >= $this->config->maxKeys) {
                $out[] = '_truncated';
                break;
            }

            $out[] = $this->sanitizeValue($value, $depth);
        }

        return $out;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        if (is_array($value)) {
            if ($depth >= $this->config->maxDepth) {
                return ['_depth_limited' => true];
            }

            return array_is_list($value)
                ? $this->sanitizeList($value, $depth + 1)
                : $this->sanitizeAssoc($value, $depth + 1);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::RFC3339_EXTENDED);
        }

        if ($value instanceof JsonSerializable) {
            return $this->sanitizeValue($value->jsonSerialize(), $depth);
        }

        if ($value instanceof Stringable) {
            return $this->sanitizeString((string) $value);
        }

        if (is_object($value)) {
            return '['.$value::class.']';
        }

        return '[unsupported]';
    }

    private function sanitizeString(string $value): string
    {
        if ($this->secrets->looksSecret($value) !== null) {
            return $this->config->marker;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        if (mb_strlen($value) > $this->config->maxStringLength) {
            return mb_substr($value, 0, $this->config->maxStringLength).'…[truncated]';
        }

        return $value;
    }

    private function pseudonym(string $value): string
    {
        $normalized = mb_strtolower(trim($value));

        if ($this->config->pseudonymKey === null || $this->config->pseudonymKey === '') {
            // No pepper available: never emit a plain, dictionary-attackable hash.
            return $this->config->marker;
        }

        return hash_hmac('sha256', $normalized, $this->config->pseudonymKey);
    }
}
