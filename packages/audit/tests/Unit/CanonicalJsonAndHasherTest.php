<?php

declare(strict_types=1);

use Efnext\Security\Audit\Exceptions\CanonicalizationFailed;
use Efnext\Security\Audit\Hashing\CanonicalJson;
use Efnext\Security\Audit\Hashing\EventHasher;
use Efnext\Security\Audit\Hashing\HashableRecord;

function hashableRecord(array $overrides = []): array
{
    return HashableRecord::build(...array_merge([
        'id' => '01J8ZQ0Y3K5X6W7V8T9S0R1P2N',
        'chainKey' => 'tenant:9c5f3e2a-7d1b-4e8f-a2c6-0b1d2e3f4a5b',
        'tenantId' => '9c5f3e2a-7d1b-4e8f-a2c6-0b1d2e3f4a5b',
        'sequence' => 7,
        'previousHash' => str_repeat('a', 64),
        'occurredAtMicros' => 1758391200123456,
        'actorType' => 'user',
        'actorId' => '42',
        'actorLabel' => null,
        'action' => 'domain.updated',
        'resourceType' => 'domain',
        'resourceId' => 'dom_7f3k9zq2m4x8c1bt',
        'outcome' => 'allowed',
        'metadata' => ['old' => 'inactive', 'new' => 'active'],
        'requestId' => 'req_1',
        'correlationId' => null,
        'ipAddress' => '203.0.113.10',
        'userAgent' => 'curl',
    ], $overrides));
}

it('encodes independent of key order and preserves list order and zero fractions', function (): void {
    expect(CanonicalJson::encode(['b' => ['z' => 1, 'a' => [3, 2]], 'a' => 'x', 'f' => 1.0]))
        ->toBe(CanonicalJson::encode(['f' => 1.0, 'a' => 'x', 'b' => ['a' => [3, 2], 'z' => 1]]))
        ->toBe('{"a":"x","b":{"a":[3,2],"z":1},"f":1.0}')
        ->and(CanonicalJson::encode(['s' => 'ä/ö']))->toBe('{"s":"ä/ö"}');
});

it('rejects values that cannot be canonicalised', function (): void {
    expect(fn () => CanonicalJson::encode(['bad' => "\xB1\x31"]))->toThrow(CanonicalizationFailed::class)
        ->and(fn () => CanonicalJson::encode(['nan' => NAN]))->toThrow(CanonicalizationFailed::class)
        ->and(fn () => CanonicalJson::encode(['obj' => new stdClass]))->toThrow(CanonicalizationFailed::class);
});

it('round-trips metadata the way the database will return it', function (): void {
    $trip = CanonicalJson::roundTrip(['when' => new DateTimeImmutable('2026-09-20T10:00:00Z'), 'n' => 1.0, 'nested' => ['k' => true]]);

    expect($trip)->toEqual(['when' => '2026-09-20T10:00:00.000000Z', 'n' => 1.0, 'nested' => ['k' => true]])
        ->and($trip['n'])->toBeFloat();
});

it('covers every descriptive field of the record', function (string $field, mixed $value): void {
    $hasher = new EventHasher;

    expect($hasher->hash(hashableRecord([$field => $value])))->not->toBe($hasher->hash(hashableRecord()));
})->with([
    ['id', '01J8ZQ0Y3K5X6W7V8T9S0R1P2M'],
    ['chainKey', 'global'],
    ['tenantId', null],
    ['sequence', 8],
    ['previousHash', null],
    ['occurredAtMicros', 1758391200123457],
    ['actorType', 'system'],
    ['actorId', '43'],
    ['actorLabel', 'someone'],
    ['action', 'domain.deleted'],
    ['resourceType', 'host'],
    ['resourceId', 'dom_7f3k9zq2m4x8c1bx'],
    ['outcome', 'denied'],
    ['metadata', ['old' => 'inactive']],
    ['requestId', 'req_2'],
    ['correlationId', 'corr'],
    ['ipAddress', '203.0.113.11'],
    ['userAgent', 'wget'],
]);

it('is deterministic and free of delimiter ambiguity', function (): void {
    $hasher = new EventHasher;

    expect($hasher->hash(hashableRecord()))->toBe($hasher->hash(hashableRecord()))
        ->and($hasher->hash(hashableRecord()))->toMatch('/^[0-9a-f]{64}$/')
        ->and($hasher->hash(hashableRecord(['action' => 'a.b', 'resourceType' => 'c'])))
        ->not->toBe($hasher->hash(hashableRecord(['action' => 'a', 'resourceType' => 'b.c'])))
        ->and($hasher->supports(HashableRecord::VERSION))->toBeTrue()
        ->and($hasher->supports(1))->toBeFalse();
});

it('reads stored metadata back in canonical key order whatever the database returned', function (): void {
    // MySQL's JSON type reorders object keys (by length, then alphabetically); callers must not notice.
    $cast = new Efnext\Security\Audit\Eloquent\CanonicalJsonCast;
    $model = new Efnext\Security\Audit\Eloquent\AuditEvent;

    expect($cast->get($model, 'metadata', '{"from":null,"actor_id":"1","nested":{"b":1.0,"a":[2,{"z":1,"y":2}]}}', []))
        ->toBe(['actor_id' => '1', 'from' => null, 'nested' => ['a' => [2, ['y' => 2, 'z' => 1]], 'b' => 1.0]])
        ->and($cast->get($model, 'metadata', null, []))->toBeNull()
        ->and($cast->get($model, 'metadata', '', []))->toBeNull()
        ->and($cast->get($model, 'metadata', '{not json', []))->toBeNull()
        ->and($cast->set($model, 'metadata', ['b' => 1, 'a' => 2], []))->toBe('{"a":2,"b":1}')
        ->and($cast->set($model, 'metadata', null, []))->toBeNull();
});
