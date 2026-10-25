<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Sanitizing;

/**
 * Matches metadata keys against token patterns.
 *
 * Keys are lowercased and split into tokens on non-alphanumerics and camelCase
 * boundaries: "passwordConfirmation" -> [password, confirmation],
 * "x-api-key" -> [x, api, key], "session_count" -> [session, count].
 *
 * A pattern ("api key", "password") matches when its tokens occur as a
 * contiguous run inside the key's tokens. That keeps "author" apart from
 * "auth" and "session_count" apart from "session_id".
 */
final class KeyMatcher
{
    /** @var list<list<string>> */
    private array $patterns = [];

    /** @var array<string, true> */
    private array $allow = [];

    /**
     * @param  list<string>  $patterns  e.g. ['password', 'api key']
     * @param  list<string>  $allowKeys  exact keys (case-insensitive) that never match
     */
    public function __construct(array $patterns, array $allowKeys = [])
    {
        foreach ($patterns as $pattern) {
            $tokens = self::tokenize($pattern);

            if ($tokens !== []) {
                $this->patterns[] = $tokens;
            }
        }

        foreach ($allowKeys as $key) {
            $this->allow[strtolower(trim($key))] = true;
        }
    }

    public function matches(string $key): bool
    {
        if (isset($this->allow[strtolower(trim($key))])) {
            return false;
        }

        $tokens = self::tokenize($key);

        if ($tokens === []) {
            return false;
        }

        foreach ($this->patterns as $pattern) {
            if (self::containsRun($tokens, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function tokenize(string $key): array
    {
        // Split camelCase and PascalCase before lowercasing.
        $spaced = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $key);
        $parts = preg_split('/[^a-z0-9]+/', strtolower($spaced)) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    private static function containsRun(array $haystack, array $needle): bool
    {
        $n = count($needle);
        $limit = count($haystack) - $n;

        for ($i = 0; $i <= $limit; $i++) {
            if (array_slice($haystack, $i, $n) === $needle) {
                return true;
            }
        }

        return false;
    }
}
