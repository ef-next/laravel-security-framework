<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Sanitizing;

final readonly class SanitizerConfig
{
    /**
     * @param  list<string>  $redactKeyPatterns
     * @param  list<string>  $pseudonymizeKeyPatterns
     * @param  list<string>  $allowKeys
     */
    public function __construct(
        public array $redactKeyPatterns,
        public array $pseudonymizeKeyPatterns,
        public array $allowKeys = [],
        public string $marker = '[REDACTED]',
        public int $maxDepth = 6,
        public int $maxKeys = 100,
        public int $maxStringLength = 1024,
        public ?string $pseudonymKey = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config, ?string $fallbackPseudonymKey = null): self
    {
        $pseudonymKey = $config['pseudonym_key'] ?? null;

        return new self(
            redactKeyPatterns: self::strings($config['redact_key_patterns'] ?? []),
            pseudonymizeKeyPatterns: self::strings($config['pseudonymize_key_patterns'] ?? []),
            allowKeys: self::strings($config['allow_keys'] ?? []),
            marker: is_string($config['marker'] ?? null) && $config['marker'] !== '' ? $config['marker'] : '[REDACTED]',
            maxDepth: max(1, (int) ($config['max_depth'] ?? 6)),
            maxKeys: max(1, (int) ($config['max_keys'] ?? 100)),
            maxStringLength: max(16, (int) ($config['max_string_length'] ?? 1024)),
            pseudonymKey: is_string($pseudonymKey) && $pseudonymKey !== '' ? $pseudonymKey : $fallbackPseudonymKey,
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && trim($v) !== '')) : [];
    }
}
