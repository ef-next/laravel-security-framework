<?php

declare(strict_types=1);

use Efnext\Security\Audit\Exceptions\CanonicalizationFailed;
use Efnext\Security\Audit\Hashing\CanonicalJson;
use Efnext\Security\Audit\Sanitizing\DefaultMetadataSanitizer;
use Efnext\Security\Audit\Sanitizing\KeyMatcher;
use Efnext\Security\Audit\Sanitizing\SanitizerConfig;
use Efnext\Security\Audit\Sanitizing\SecretDetector;

/*
 * Exact boundaries of the sanitiser, its configuration, the key matcher, the
 * secret detector and canonical JSON. Written against the mutation report: each
 * case pins a limit or branch that no other test distinguished.
 */

enum Suit
{
    case Hearts;
}

it('parses the sanitizer configuration with defaults, floors and precedence', function (): void {
    $defaults = SanitizerConfig::fromArray([], 'fallback-pepper');

    expect($defaults->marker)->toBe('[REDACTED]')
        ->and($defaults->maxDepth)->toBe(6)
        ->and($defaults->maxKeys)->toBe(100)
        ->and($defaults->maxStringLength)->toBe(1024)
        ->and($defaults->pseudonymKey)->toBe('fallback-pepper')
        ->and($defaults->redactKeyPatterns)->toBe([])
        ->and($defaults->allowKeys)->toBe([]);

    $custom = SanitizerConfig::fromArray([
        'marker' => '***',
        'max_depth' => '3',
        'max_keys' => 0,            // floored to 1
        'max_string_length' => 2,   // floored to 16
        'pseudonym_key' => 'explicit-pepper',
        'redact_key_patterns' => ['password', '', '  ', 42, 'api key'],
        'allow_keys' => [' Token_Type ', null],
    ], 'fallback-pepper');

    expect($custom->marker)->toBe('***')
        ->and($custom->maxDepth)->toBe(3)
        ->and($custom->maxKeys)->toBe(1)
        ->and($custom->maxStringLength)->toBe(16)
        ->and($custom->pseudonymKey)->toBe('explicit-pepper')
        ->and($custom->redactKeyPatterns)->toBe(['password', 'api key'])
        ->and($custom->allowKeys)->toBe([' Token_Type ']);

    // Empty marker or pseudonym key fall back; non-array pattern lists are ignored.
    $odd = SanitizerConfig::fromArray(['marker' => '', 'pseudonym_key' => '', 'redact_key_patterns' => 'password'], null);

    expect($odd->marker)->toBe('[REDACTED]')
        ->and($odd->pseudonymKey)->toBeNull()
        ->and($odd->redactKeyPatterns)->toBe([]);
});

it('truncates exactly at the configured number of keys, for maps and lists', function (): void {
    $sanitizer = new DefaultMetadataSanitizer(new SanitizerConfig([], [], maxKeys: 3, maxStringLength: 16));

    $three = $sanitizer->sanitize(['a' => 1, 'b' => 2, 'c' => 3]);
    $four = $sanitizer->sanitize(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);

    expect($three)->toBe(['a' => 1, 'b' => 2, 'c' => 3])
        ->and($four)->toBe(['a' => 1, 'b' => 2, 'c' => 3, '_truncated' => true]);

    $list = $sanitizer->sanitize(['l' => [1, 2, 3]])['l'];
    $long = $sanitizer->sanitize(['l' => [1, 2, 3, 4, 5]])['l'];

    expect($list)->toBe([1, 2, 3])
        ->and($long)->toBe([1, 2, 3, '_truncated']);

    // Integer keys become strings; nothing is silently dropped below the limit.
    expect($sanitizer->sanitize([7 => 'x', 8 => 'y']))->toBe(['7' => 'x', '8' => 'y']);
});

it('cuts strings exactly at the configured length and repairs invalid UTF-8', function (): void {
    $sanitizer = new DefaultMetadataSanitizer(new SanitizerConfig([], [], maxStringLength: 16));

    $exact = str_repeat('ä', 16);   // 16 characters, 32 bytes: length is measured in characters
    $over = str_repeat('ä', 17);

    expect($sanitizer->sanitize(['s' => $exact])['s'])->toBe($exact)
        ->and($sanitizer->sanitize(['s' => $over])['s'])->toBe($exact.'…[truncated]')
        ->and($sanitizer->sanitize(['s' => "ok\xB1\x31"])['s'])->toBe('ok?1');
});

it('limits nesting depth exactly', function (): void {
    $sanitizer = new DefaultMetadataSanitizer(new SanitizerConfig([], [], maxDepth: 2));

    expect($sanitizer->sanitize(['a' => ['b' => 'leaf']]))->toBe(['a' => ['b' => 'leaf']])
        ->and($sanitizer->sanitize(['a' => ['b' => ['c' => 'deep']]]))->toBe(['a' => ['b' => ['_depth_limited' => true]]])
        ->and($sanitizer->sanitize(['a' => [[1, [2]]]]))->toBe(['a' => [['_depth_limited' => true]]]);
});

it('normalises enums, JsonSerializable and Stringable values and pseudonymises case- and space-insensitively', function (): void {
    $sanitizer = new DefaultMetadataSanitizer(SanitizerConfig::fromArray(['pseudonymize_key_patterns' => ['email']], 'pepper'));

    $json = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['nested' => 'value'];
        }
    };
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'stringable';
        }
    };

    $out = $sanitizer->sanitize([
        'unit' => Suit::Hearts,
        'json' => $json,
        'str' => $stringable,
        'secretish' => new class implements Stringable
        {
            public function __toString(): string
            {
                return 'AKIAIOSFODNN7EXAMPLE';
            }
        },
        'email' => '  CEO@Acme.example ',
    ]);

    expect($out['unit'])->toBe('Hearts')
        ->and($out['json'])->toBe(['nested' => 'value'])
        ->and($out['str'])->toBe('stringable')
        ->and($out['secretish'])->toBe('[REDACTED]')
        ->and($out['email_hash'])->toBe(hash_hmac('sha256', 'ceo@acme.example', 'pepper'))
        ->and($out)->not->toHaveKey('email');

    // Without any pepper, pseudonyms are replaced by the marker rather than a plain hash.
    $bare = new DefaultMetadataSanitizer(SanitizerConfig::fromArray(['pseudonymize_key_patterns' => ['email'], 'pseudonym_key' => ''], null));

    expect($bare->sanitize(['email' => 'a@b.c'])['email_hash'])->toBe('[REDACTED]');
});

it('matches keys as token runs and honours allow keys regardless of case and whitespace', function (): void {
    $matcher = new KeyMatcher(['api key', 'password', 'session id'], ['Token_Type', ' email_verified ']);

    expect($matcher->matches('apiKey'))->toBeTrue()
        ->and($matcher->matches('x-api-key'))->toBeTrue()
        ->and($matcher->matches('API_KEY'))->toBeTrue()
        ->and($matcher->matches('session_id'))->toBeTrue()
        ->and($matcher->matches('session'))->toBeFalse()          // partial run
        ->and($matcher->matches('id_session'))->toBeFalse()       // wrong order
        ->and($matcher->matches('key_api'))->toBeFalse()
        ->and($matcher->matches('passwords'))->toBeFalse()        // whole tokens only
        ->and($matcher->matches('token_type'))->toBeFalse()       // allowed, case-insensitive
        ->and($matcher->matches('TOKEN_TYPE'))->toBeFalse()
        ->and($matcher->matches('  email_verified'))->toBeFalse() // allowed, trimmed
        ->and($matcher->matches(''))->toBeFalse()
        ->and($matcher->matches('___'))->toBeFalse();

    expect(KeyMatcher::tokenize('HTTPResponseCode'))->toBe(['http', 'response', 'code'])
        ->and(KeyMatcher::tokenize('apiKey2Fa'))->toBe(['api', 'key2', 'fa']);
});

it('recognises card numbers only within Luhn and length bounds', function (): void {
    $detector = new SecretDetector;

    expect($detector->looksSecret('4222222222222'))->toBe('card_number')            // 13 digits, valid
        ->and($detector->looksSecret('5105105105105100'))->toBe('card_number')      // doubled digits above 9
        ->and($detector->looksSecret('6011 0009 9013 9424'))->toBe('card_number')
        ->and($detector->looksSecret('4111-1111-1111-1111'))->toBe('card_number')
        ->and($detector->looksSecret('4222222222223'))->toBeNull()                 // 13 digits, Luhn fails
        ->and($detector->looksSecret('42222222222'))->toBeNull()                   // too short
        ->and($detector->looksSecret('4222222222222222222222'))->toBeNull()        // 22 digits: too long
        ->and($detector->looksSecret('0000000000000'))->toBe('card_number')        // Luhn-valid zeros: still redacted, better safe
        ->and($detector->looksSecret(''))->toBeNull()
        ->and($detector->looksSecret('4222222222222 '))->toBeNull();               // trailing space is not a card number
});

it('switches scanning strategy exactly at the window and hard limits', function (): void {
    $detector = new SecretDetector;
    $jwtPrefix = 'eyJhbGciOiJIUzI1NiJ9.';
    $fill = fn (int $bytes): string => substr(str_repeat('abcdefghij', ($bytes >> 3) + 2), 0, $bytes);

    // Exactly WINDOW bytes: whole-value scan (anchored pattern with $ must match).
    $exact = $jwtPrefix.$fill(SecretDetector::WINDOW - strlen($jwtPrefix) - 12).'.'.$fill(11);
    expect(strlen($exact))->toBe(SecretDetector::WINDOW)->and($detector->looksSecret($exact))->toBe('jwt');

    // One byte more: windowed scan; the JWT prefix rule catches it.
    expect($detector->looksSecret($exact.'x'))->toBe('jwt');

    // A token in the very last bytes of a long value is still found.
    $tail = $fill(SecretDetector::WINDOW * 3).' AKIAIOSFODNN7EXAMPLE';
    expect($detector->looksSecret($tail))->toBe('aws_access_key');

    // Hard limit: exactly 1 MiB is scanned (harmless), one more byte is oversized.
    expect($detector->looksSecret(str_repeat('a', SecretDetector::HARD_LIMIT)))->toBeNull()
        ->and($detector->looksSecret(str_repeat('a', SecretDetector::HARD_LIMIT + 1)))->toBe('oversized');
});

it('canonicalises enums, JsonSerializable and Stringable values and rejects infinities', function (): void {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'str';
        }
    };
    $json = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['b' => 2, 'a' => 1];
        }
    };

    expect(CanonicalJson::encode(['e' => Efnext\Security\Audit\Enums\Outcome::Denied, 's' => $stringable, 'j' => $json, 'l' => [3, [2, 1]]]))
        ->toBe('{"e":"denied","j":{"a":1,"b":2},"l":[3,[2,1]],"s":"str"}');

    // Dates are normalised to UTC with microseconds, whatever their zone.
    expect(CanonicalJson::encode(['d' => new DateTimeImmutable('2026-09-22 12:00:00.5', new DateTimeZone('Europe/Berlin'))]))
        ->toBe('{"d":"2026-09-22T10:00:00.500000Z"}');

    expect(fn () => CanonicalJson::encode(['inf' => INF]))->toThrow(CanonicalizationFailed::class)
        ->and(fn () => CanonicalJson::encode(['ninf' => -INF]))->toThrow(CanonicalizationFailed::class)
        ->and(fn () => CanonicalJson::encode(['nan' => NAN]))->toThrow(CanonicalizationFailed::class)
        ->and(fn () => CanonicalJson::encode(['o' => new stdClass]))->toThrow(CanonicalizationFailed::class, 'stdClass');

    // Unit enums have no value to serialise and are rejected too.
    expect(fn () => CanonicalJson::encode(['u' => Suit::Hearts]))->toThrow(CanonicalizationFailed::class);
});

it('detects every secret kind it advertises, and only those', function (): void {
    $detector = new SecretDetector;

    $positives = [
        'jwt' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U',
        'bearer' => 'Bearer 8f3a9c1e2b',
        'pem_private_key' => "-----BEGIN EC PRIVATE KEY-----\nMHc",
        'aws_access_key' => 'ASIAIOSFODNN7EXAMPLE',
        'github_token' => 'ghp_'.str_repeat('Ab1', 12),
        'slack_token' => 'xoxb-1234567890-abcdefghij',
        'stripe_key' => 'rk_test_'.str_repeat('Zz9', 6),
        'google_api_key' => 'AIza'.str_repeat('a', 35),
        'card_number' => '4111 1111 1111 1111',
        'oversized' => str_repeat('z', SecretDetector::HARD_LIMIT + 1),
    ];

    expect(array_keys($positives))->toBe(SecretDetector::kinds());

    foreach ($positives as $kind => $value) {
        expect($detector->looksSecret($value))->toBe($kind, $kind);

        // Anchored shapes (a whole token, an Authorization value, a card number) are not hunted inside prose; the others are.
        $embedded = match ($kind) {
            'jwt', 'bearer', 'card_number' => null,
            default => $kind,
        };
        expect($detector->looksSecret('note: '.$value.' end'))->toBe($embedded, $kind.' embedded');
    }

    // Near misses stay readable.
    foreach ([
        'Basic',                                   // scheme without credentials
        'eyJhbGciOiJIUzI1NiJ9.short',              // two segments only
        'AKIA1234567890ABCDE',                     // 15 characters after the prefix
        'ghz_'.str_repeat('a', 36),                // unknown GitHub prefix
        'xoxz-1234567890-abcdefghij',              // unknown Slack prefix
        'pk_live_'.str_repeat('a', 24),            // publishable Stripe key
        'AIza'.str_repeat('a', 34),                // one character short
        'BEGIN PRIVATE KEY',                       // no PEM armour
        'order 4111-1111-1111-1112',               // Luhn fails
    ] as $harmless) {
        expect($detector->looksSecret($harmless))->toBeNull($harmless);
    }
});
