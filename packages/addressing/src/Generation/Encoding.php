<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Generation;

use InvalidArgumentException;

/**
 * Body encodings for public ids. Both produce lowercase alphanumerics only.
 */
enum Encoding: string
{
    /** Crockford base32 (0-9 a-z without i, l, o, u): 8 chars per 5 bytes. */
    case Base32 = 'base32';

    /** Lowercase hexadecimal: 2 chars per byte. */
    case Hex = 'hex';

    private const string BASE32_ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public static function fromName(string $name): self
    {
        return self::tryFrom(strtolower(trim($name)))
            ?? throw new InvalidArgumentException(sprintf('Unknown public id encoding "%s". Use "base32" or "hex".', $name));
    }

    public function encode(string $bytes): string
    {
        return match ($this) {
            self::Hex => bin2hex($bytes),
            self::Base32 => self::base32($bytes),
        };
    }

    /** Number of characters produced for the given number of bytes. */
    public function length(int $bytes): int
    {
        return match ($this) {
            self::Hex => $bytes * 2,
            self::Base32 => intdiv($bytes * 8 + 4, 5),
        };
    }

    private static function base32(string $bytes): string
    {
        $output = '';
        $buffer = 0;
        $bits = 0;
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $output .= self::BASE32_ALPHABET[($buffer >> $bits) & 0x1F];
            }

            // Keep the buffer small; only the low $bits bits are still needed.
            $buffer &= (1 << $bits) - 1;
        }

        if ($bits > 0) {
            $output .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 0x1F];
        }

        return $output;
    }
}
