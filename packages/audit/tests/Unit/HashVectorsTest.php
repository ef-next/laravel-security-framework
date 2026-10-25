<?php

declare(strict_types=1);

use Efnext\Security\Audit\Hashing\CanonicalJson;
use Efnext\Security\Audit\Hashing\EventHasher;
use Efnext\Security\Audit\Hashing\HashableRecord;
use Efnext\Security\Audit\Signing\HmacSigner;
use Efnext\Security\Audit\Signing\Keyring;

/*
 * Known-answer tests for the audit hash format. Every other hashing test
 * compares hashes with each other; these compare them with fixed values
 * recorded in tests/Fixtures/hash-vectors.json. A change to the hash
 * version, the domain string, the set or spelling of hashed fields, the
 * canonical JSON rules or the HMAC construction breaks this suite on
 * purpose: such a change is a new hash version, never a silent edit.
 *
 * Note for the mutation report: constants such as HashableRecord::VERSION or
 * CanonicalJson::FLAGS have no executable line, so pcov cannot attribute a test
 * to them and their mutants stay "uncovered" there. This suite fails on such a
 * change all the same (verified by bumping VERSION and by swapping a JSON flag).
 */

/** @return array<string, mixed> */
function hashVectors(): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/hash-vectors.json'), true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

it('pins the hash version, the domain string and the algorithm', function (): void {
    $fixture = hashVectors();

    expect(HashableRecord::VERSION)->toBe(2)
        ->and(HashableRecord::DOMAIN)->toBe("efnext-audit-v2\n")
        ->and(bin2hex(HashableRecord::DOMAIN))->toBe('65666e6578742d61756469742d76320a')
        ->and(EventHasher::ALGORITHM)->toBe('sha256')
        ->and($fixture['version'])->toBe(HashableRecord::VERSION)
        ->and($fixture['domain'])->toBe(HashableRecord::DOMAIN)
        ->and($fixture['algorithm'])->toBe(EventHasher::ALGORITHM);
});

it('hashes exactly the pinned set of fields', function (): void {
    $record = HashableRecord::build(...hashVectors()['vectors'][0]['input']);

    expect(array_keys($record))->toBe(['v', 'id', 'chain', 'tenant_id', 'sequence', 'previous_hash', 'occurred_at_us', 'actor', 'action', 'resource', 'outcome', 'metadata', 'request'])
        ->and(array_keys($record['actor']))->toBe(['type', 'id', 'label'])
        ->and(array_keys($record['resource']))->toBe(['type', 'id'])
        ->and(array_keys($record['request']))->toBe(['id', 'correlation_id', 'ip', 'user_agent'])
        ->and($record['v'])->toBe(2);
});

it('reproduces every recorded canonical encoding and event hash', function (array $vector): void {
    $record = HashableRecord::build(...$vector['input']);
    $canonical = CanonicalJson::encode($record);

    expect($canonical)->toBe($vector['canonical_json'])
        ->and((new EventHasher)->hash($record))->toBe($vector['event_hash'])
        // The recipe is plain SHA-256 over domain || canonical JSON; anyone can recompute it without this code base.
        ->and(hash('sha256', HashableRecord::DOMAIN.$vector['canonical_json']))->toBe($vector['event_hash'])
        ->and(strlen($vector['event_hash']))->toBe(64);
})->with(fn () => collect(hashVectors()['vectors'])->mapWithKeys(fn (array $vector) => [$vector['name'] => [$vector]])->all());

it('chains the second vector to the first through previous_hash', function (): void {
    [$first, $second] = hashVectors()['vectors'];

    expect($second['input']['previousHash'])->toBe($first['event_hash'])
        ->and($first['input']['previousHash'])->toBeNull();
});

it('reproduces the recorded HMAC signatures and rejects a forged one', function (): void {
    $fixture = hashVectors()['signatures'];
    $signer = new HmacSigner(Keyring::fromString($fixture['keyring'], $fixture['active_key']));

    foreach ($fixture['entries'] as $entry) {
        $signature = $signer->sign($entry['event_hash']);

        expect($signature?->keyId)->toBe($entry['key_id'])
            ->and($signature?->value)->toBe($entry['signature'])
            ->and($signer->verify($entry['event_hash'], $entry['key_id'], $entry['signature'])->name)->toBe('Valid')
            ->and($signer->verify($entry['event_hash'], $entry['key_id'], strrev($entry['signature']))->name)->toBe('Invalid');
    }

    // The construction itself, independent of the Signer class: HMAC-SHA256 over the hex event hash with the raw key bytes.
    $rawKey = base64_decode(explode(':', $fixture['keyring'])[2], true);

    expect($rawKey)->toBe(implode('', array_map('chr', range(0, 31))))
        ->and(hash_hmac('sha256', $fixture['entries'][0]['event_hash'], (string) $rawKey))->toBe($fixture['entries'][0]['signature']);
});

it('documents that empty metadata canonicalises to an empty JSON array', function (): void {
    // PHP cannot tell an empty object from an empty list; the format therefore fixes `[]`.
    // Another implementation must encode an empty metadata object the same way.
    expect(CanonicalJson::encode(['metadata' => []]))->toBe('{"metadata":[]}')
        ->and(hashVectors()['vectors'][1]['canonical_json'])->toContain('"metadata":[]');
});
