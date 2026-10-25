<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Sanitizing;

/**
 * Recognises values that are secrets regardless of their key.
 *
 * Values are scanned completely, whatever their size: short values in one go,
 * long values in overlapping windows (bounded regex work per window), and
 * absurdly large values are treated as secrets outright rather than stored
 * unscanned. Skipping the scan for big values would let a long JWT or PEM key
 * through as a truncated, plain-text prefix.
 */
final class SecretDetector
{
    /** Longest value scanned as a whole; longer values are scanned window by window. */
    public const int WINDOW = 8192;

    /** Windows overlap by this much so a token straddling a boundary is still seen. */
    private const int OVERLAP = 512;

    /** Values beyond this size are not stored at all; 'oversized' is reported as the secret kind. */
    public const int HARD_LIMIT = 1_048_576;

    /** Patterns anchored at the start of the value; only meaningful for the first window. */
    private const array ANCHORED = [
        'jwt' => '/^[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}$/D',
        'bearer' => '/^\s*(?:Bearer|Basic|Digest|Token)\s+\S+/i',
    ];

    /** Start-of-value shapes of secrets whose tail may lie beyond the first window. */
    private const array ANCHORED_PREFIX = [
        'jwt' => '/^eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/',
    ];

    /** Patterns that may occur anywhere in the value. */
    private const array ANYWHERE = [
        'pem_private_key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'aws_access_key' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        'github_token' => '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/',
        'slack_token' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
        'stripe_key' => '/\b[sr]k_(?:live|test)_[A-Za-z0-9]{16,}\b/',
        'google_api_key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
    ];

    /**
     * Every kind looksSecret() can report, in detection order. Tests pin this list
     * so a pattern cannot be added or dropped without a matching test.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return array_values(array_unique([
            ...array_keys(self::ANCHORED),
            ...array_keys(self::ANCHORED_PREFIX),
            ...array_keys(self::ANYWHERE),
            'card_number',
            'oversized',
        ]));
    }

    public function looksSecret(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $length = strlen($value);

        if ($length > self::HARD_LIMIT) {
            return 'oversized';
        }

        if ($length <= self::WINDOW) {
            return self::match(self::ANCHORED, $value)
                ?? self::match(self::ANYWHERE, $value)
                ?? (self::isCardNumber($value) ? 'card_number' : null);
        }

        $first = substr($value, 0, self::WINDOW);

        if (($kind = self::match(self::ANCHORED, $first) ?? self::match(self::ANCHORED_PREFIX, $first)) !== null) {
            return $kind;
        }

        for ($offset = 0; $offset < $length; $offset += self::WINDOW - self::OVERLAP) {
            if (($kind = self::match(self::ANYWHERE, substr($value, $offset, self::WINDOW))) !== null) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private static function match(array $patterns, string $value): ?string
    {
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return $name;
            }
        }

        return null;
    }

    /**
     * 13-19 digits (spaces or dashes allowed) that pass the Luhn check.
     */
    private static function isCardNumber(string $value): bool
    {
        if (preg_match('/^[\d][\d \-]{11,24}[\d]$/D', $value) !== 1) {
            return false;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
