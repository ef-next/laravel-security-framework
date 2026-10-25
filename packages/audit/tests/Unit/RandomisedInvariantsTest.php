<?php

declare(strict_types=1);

use Efnext\Security\Addressing\Generation\Encoding;
use Efnext\Security\Addressing\Identifiers\GlobalObjectId;
use Efnext\Security\Addressing\Identifiers\PublicId;
use Efnext\Security\Audit\Hashing\CanonicalJson;
use Efnext\Security\Audit\Hashing\EventHasher;
use Efnext\Security\Audit\Hashing\HashableRecord;
use Efnext\Security\Audit\Sanitizing\DefaultMetadataSanitizer;
use Efnext\Security\Audit\Sanitizing\KeyMatcher;
use Efnext\Security\Audit\Sanitizing\SanitizerConfig;
use Efnext\Security\Audit\Sanitizing\SecretDetector;
use Efnext\Security\Tenant\Context\TenantId;

/*
 * Property-style tests: random inputs, invariants that must hold for all of
 * them. The seed is printed on failure so a case can be replayed with
 * RANDOM_SEED=<n>. Iteration counts are modest so the suite stays fast; raise
 * RANDOM_ITERATIONS for a long soak.
 */

function rng(): Random\Randomizer
{
    $seed = getenv('RANDOM_SEED');
    $seed = $seed !== false && $seed !== '' ? (int) $seed : random_int(1, PHP_INT_MAX);

    test()->seed = $seed; // shown by the failure message below

    return new Random\Randomizer(new Random\Engine\Mt19937($seed));
}

function iterations(int $default): int
{
    $env = getenv('RANDOM_ITERATIONS');

    return $env !== false && (int) $env > 0 ? (int) $env : $default;
}

function randomScalar(Random\Randomizer $r, int $depth): mixed
{
    return match ($r->getInt(0, 9)) {
        0 => null,
        1 => $r->getInt(0, 1) === 1,
        2 => $r->getInt(PHP_INT_MIN, PHP_INT_MAX),
        3 => $r->getInt(-1_000_000, 1_000_000) / 8,             // exact binary fractions survive JSON
        4 => (float) $r->getInt(-1_000_000, 1_000_000),          // whole floats (zero fraction) must stay floats
        5 => randomUtf8($r, $r->getInt(0, 40)),
        6 => $depth < 4 ? randomArray($r, $depth + 1) : 'leaf',
        7 => $depth < 4 ? randomList($r, $depth + 1) : [],
        default => randomUtf8($r, $r->getInt(0, 12)),
    };
}

function randomUtf8(Random\Randomizer $r, int $length): string
{
    $alphabet = ['a', 'Z', '0', ' ', '_', '-', '.', '/', '"', '\\', 'ä', 'ß', '€', '😀', "\n", "\t", '中', 'é', '{', '}'];
    $out = '';

    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[$r->getInt(0, count($alphabet) - 1)];
    }

    return $out;
}

/** @return array<string, mixed> */
function randomArray(Random\Randomizer $r, int $depth = 0): array
{
    $out = [];
    $count = $r->getInt(0, 6);

    for ($i = 0; $i < $count; $i++) {
        // keys that stay plain (the sanitiser is exercised separately)
        $out['k'.$r->getInt(0, 999).'_'.$i] = randomScalar($r, $depth);
    }

    return $out;
}

/** @return list<mixed> */
function randomList(Random\Randomizer $r, int $depth = 0): array
{
    $out = [];
    $count = $r->getInt(0, 5);

    for ($i = 0; $i < $count; $i++) {
        $out[] = randomScalar($r, $depth);
    }

    return $out;
}

/** Shuffle associative keys recursively without changing values. */
function shuffleKeys(Random\Randomizer $r, array $value): array
{
    if (array_is_list($value)) {
        return array_map(fn ($v) => is_array($v) ? shuffleKeys($r, $v) : $v, $value);
    }

    $keys = $r->shuffleArray(array_keys($value));
    $out = [];

    foreach ($keys as $key) {
        $out[$key] = is_array($value[$key]) ? shuffleKeys($r, $value[$key]) : $value[$key];
    }

    return $out;
}

it('canonical JSON is key-order independent, stable across round trips and byte-identical to its re-encoding', function (): void {
    $r = rng();

    for ($i = 0; $i < iterations(300); $i++) {
        $value = randomArray($r);
        $encoded = CanonicalJson::encode($value);
        $shuffled = CanonicalJson::encode(shuffleKeys($r, $value));
        $tripped = CanonicalJson::roundTrip($value);

        expect($shuffled)->toBe($encoded, 'seed '.test()->seed.' iteration '.$i)
            ->and(CanonicalJson::encode($tripped))->toBe($encoded, 'seed '.test()->seed.' iteration '.$i)
            ->and(CanonicalJson::roundTrip($tripped))->toBe($tripped, 'seed '.test()->seed.' iteration '.$i)
            ->and(json_decode($encoded, true, 512, JSON_THROW_ON_ERROR))->toEqual($tripped);
    }
});

it('the event hash changes whenever any hashed field changes and never when nothing does', function (): void {
    $r = rng();
    $hasher = new EventHasher;
    $fields = ['id', 'chainKey', 'tenantId', 'sequence', 'previousHash', 'occurredAtMicros', 'actorType', 'actorId', 'actorLabel', 'action', 'resourceType', 'resourceId', 'outcome', 'metadata', 'requestId', 'correlationId', 'ipAddress', 'userAgent'];

    for ($i = 0; $i < iterations(150); $i++) {
        $base = [
            'id' => bin2hex($r->getBytes(13)),
            'chainKey' => $r->getInt(0, 3) === 0 ? 'global' : 'tenant:'.bin2hex($r->getBytes(16)),
            'tenantId' => $r->getInt(0, 3) === 0 ? null : bin2hex($r->getBytes(16)),
            'sequence' => $r->getInt(1, PHP_INT_MAX >> 8),
            'previousHash' => $r->getInt(0, 4) === 0 ? null : bin2hex($r->getBytes(32)),
            'occurredAtMicros' => $r->getInt(0, 4_102_444_800_000_000),
            'actorType' => ['user', 'system', 'token', 'anonymous'][$r->getInt(0, 3)],
            'actorId' => $r->getInt(0, 2) === 0 ? null : randomUtf8($r, $r->getInt(1, 20)),
            'actorLabel' => $r->getInt(0, 2) === 0 ? null : randomUtf8($r, $r->getInt(1, 20)),
            'action' => 'a'.$r->getInt(0, 999).'.b'.$r->getInt(0, 999),
            'resourceType' => 'r'.$r->getInt(0, 999),
            'resourceId' => randomUtf8($r, $r->getInt(1, 30)),
            'outcome' => ['allowed', 'denied', 'failed'][$r->getInt(0, 2)],
            'metadata' => CanonicalJson::roundTrip(randomArray($r)),
            'requestId' => $r->getInt(0, 1) === 0 ? null : bin2hex($r->getBytes(8)),
            'correlationId' => $r->getInt(0, 1) === 0 ? null : bin2hex($r->getBytes(8)),
            'ipAddress' => $r->getInt(0, 1) === 0 ? null : '203.0.113.'.$r->getInt(1, 254),
            'userAgent' => $r->getInt(0, 1) === 0 ? null : randomUtf8($r, $r->getInt(1, 30)),
        ];

        $hash = $hasher->hash(HashableRecord::build(...$base));

        expect($hasher->hash(HashableRecord::build(...shuffleKeys($r, $base))))->toBe($hash, 'seed '.test()->seed)
            ->and($hash)->toMatch('/^[0-9a-f]{64}$/');

        $field = $fields[$r->getInt(0, count($fields) - 1)];
        $mutated = $base;
        $mutated[$field] = match ($field) {
            'sequence' => $base['sequence'] + 1,
            'occurredAtMicros' => $base['occurredAtMicros'] + 1,
            'metadata' => $base['metadata'] + ['_mutated' => true],
            'tenantId', 'previousHash', 'actorId', 'actorLabel', 'requestId', 'correlationId', 'ipAddress', 'userAgent' => ($base[$field] ?? '').'x',
            default => $base[$field].'x',
        };

        expect($hasher->hash(HashableRecord::build(...$mutated)))->not->toBe($hash, 'seed '.test()->seed.' field '.$field);
    }
});

it('public ids and global object ids survive a string round trip or are rejected, never mangled', function (): void {
    $r = rng();
    $prefixes = ['dom', 'ten', 'inv', 'a1', 'abcdefghijkl'];

    for ($i = 0; $i < iterations(300); $i++) {
        $bytes = $r->getBytes($r->getInt(5, 40));
        $body = Encoding::Base32->encode($bytes);
        $prefix = $prefixes[$r->getInt(0, count($prefixes) - 1)];

        $id = PublicId::of($prefix, $body);

        expect(PublicId::fromString($id->toString())->equals($id))->toBeTrue()
            ->and(PublicId::isValid($id->toString()))->toBeTrue()
            ->and($body)->toMatch('/^[0-9a-hjkmnp-tv-z]+$/')                      // Crockford: no i, l, o, u
            ->and(strlen($body))->toBe(Encoding::Base32->length(strlen($bytes)));

        $gid = GlobalObjectId::of('svc', 'thing', $id);

        expect(GlobalObjectId::fromString($gid->toString())->equals($gid))->toBeTrue()
            ->and($gid->publicId->equals($id))->toBeTrue();

        // Any single-character corruption is either rejected or yields a *different* valid id, never the same one.
        $raw = $id->toString();
        $pos = $r->getInt(0, strlen($raw) - 1);
        $corrupted = substr_replace($raw, chr($r->getInt(32, 126)), $pos, 1);

        if ($corrupted !== $raw) {
            $parsed = PublicId::tryFromString($corrupted);

            expect($parsed === null || ! $parsed->equals($id))->toBeTrue('seed '.test()->seed.' corrupted '.$corrupted);
        }

        // Case and whitespace are never normalised away.
        expect(PublicId::isValid(strtoupper($raw)))->toBeFalse()
            ->and(PublicId::isValid(' '.$raw))->toBeFalse()
            ->and(PublicId::isValid($raw."\n"))->toBeFalse();
    }
});

it('tenant ids are canonical or invalid for arbitrary bytes', function (): void {
    $r = rng();
    $alphabet = 'abcdef0123456789-ABCDEF ._:/\'"%';

    for ($i = 0; $i < iterations(500); $i++) {
        $length = $r->getInt(0, 48);
        $candidate = '';

        for ($j = 0; $j < $length; $j++) {
            $candidate .= $alphabet[$r->getInt(0, strlen($alphabet) - 1)];
        }

        $parsed = TenantId::tryFromString($candidate);

        if ($parsed !== null) {
            expect($parsed->toString())->toBe($candidate, 'seed '.test()->seed.' candidate '.$candidate)
                ->and(TenantId::fromString($parsed->toString())->equals($parsed))->toBeTrue()
                ->and($candidate)->not->toContain(' ')
                ->and($candidate)->not->toContain("'");
        }
    }
});

it('sanitised metadata is always JSON-safe, bounded and free of the seeded secrets', function (): void {
    $r = rng();
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__, 2).'/config/audit.php';
    $sanitizer = new DefaultMetadataSanitizer(SanitizerConfig::fromArray($config['sanitizer'], 'pepper-0123456789-abcdefghijklmnop'));
    $matcher = new KeyMatcher($config['sanitizer']['redact_key_patterns'], $config['sanitizer']['allow_keys']);
    $detector = new SecretDetector;

    $secrets = [
        'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U',
        'AKIAIOSFODNN7EXAMPLE',
        "-----BEGIN RSA PRIVATE KEY-----\nMIIE",
        '4111 1111 1111 1111',
        'ghp_'.str_repeat('a', 36),
        'sk_live_'.str_repeat('b', 24),
    ];
    $secretKeys = ['password', 'apiKey', 'x-api-key', 'client_secret', 'Authorization', 'session_id'];

    for ($i = 0; $i < iterations(200); $i++) {
        $metadata = randomArray($r);

        // Plant a secret under a random key and a harmless value under a secret key.
        $planted = $secrets[$r->getInt(0, count($secrets) - 1)];
        $metadata['field'.$r->getInt(0, 99)] = $planted;
        $secretKey = $secretKeys[$r->getInt(0, count($secretKeys) - 1)];
        $metadata[$secretKey] = 'harmless-'.$r->getInt(0, 999);
        $metadata['long'] = str_repeat('x', $r->getInt(0, 3000));

        $out = $sanitizer->sanitize($metadata);
        $json = json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        expect($json)->not->toContain($planted)
            ->and($json)->not->toContain('harmless-')
            ->and($out[$secretKey])->toBe('[REDACTED]')
            ->and(strlen((string) $out['long']))->toBeLessThanOrEqual(1024 + strlen('…[truncated]'))
            ->and($matcher->matches($secretKey))->toBeTrue()
            ->and($detector->looksSecret($planted))->not->toBeNull();

        // Canonical JSON must be able to hash whatever the sanitiser produces.
        CanonicalJson::encode(CanonicalJson::roundTrip($out));
    }
});
