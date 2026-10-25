<?php

declare(strict_types=1);

use Efnext\Security\Audit\Anchoring\Anchorer;
use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Contracts\ChainVerifier;
use Efnext\Security\Audit\Enums\SignatureStatus;
use Efnext\Security\Audit\Enums\ViolationReason;
use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Audit\Records\Signature;
use Efnext\Security\Audit\Records\Violation;
use Efnext\Security\Audit\Tests\Fixtures\AppendOnly;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * Edge cases of the verifier that the mutation run showed to be unpinned:
 * incremental verification, the exact contents of violations (operators read
 * them), head bookkeeping without events, anchor signatures and bounds.
 */

beforeEach(function (): void {
    Tenancy::clear();
    $this->chain = ChainKey::fromString(Chains::TENANT_A);
});

function violation(array $violations, ViolationReason $reason): Violation
{
    foreach ($violations as $violation) {
        if ($violation->reason === $reason) {
            return $violation;
        }
    }

    throw new RuntimeException($reason->value.' not reported; got '.implode(', ', array_map(static fn (Violation $v): string => $v->describe(), $violations)));
}

it('verifies incrementally from a later sequence and links to the predecessor', function (): void {
    $events = Chains::write(Chains::TENANT_A, 5);

    $incremental = Audit::verifyTenant(Chains::TENANT_A, 3);

    expect($incremental->isIntact())->toBeTrue()
        ->and($incremental->eventsChecked)->toBe(3)
        ->and($incremental->fromSequence)->toBe(3)
        ->and($incremental->lastHash)->toBe($events[4]->event_hash);

    // A tampered predecessor breaks the link of the first verified event.
    AppendOnly::bypass(fn () => DB::table('audit_events')->where('id', $events[1]->id)->update(['event_hash' => str_repeat('0', 64)]));

    $report = Audit::verifyTenant(Chains::TENANT_A, 3);
    $mismatch = violation($report->violations, ViolationReason::PreviousHashMismatch);

    expect($mismatch->sequence)->toBe(3)
        ->and($mismatch->eventId)->toBe($events[2]->id)
        ->and($mismatch->details)->toBe(['expected_previous_hash' => str_repeat('0', 64), 'stored_previous_hash' => $events[1]->event_hash]);

    // Starting beyond the tail: the predecessor is missing, nothing is covered, the head is not judged.
    $orphan = Audit::verifyTenant(Chains::TENANT_A, 9);
    $missing = violation($orphan->violations, ViolationReason::MissingPredecessor);

    expect($missing->sequence)->toBe(8)
        ->and($orphan->eventsChecked)->toBe(0)
        ->and(count($orphan->violations))->toBe(1);

    // fromSequence below 1 is clamped, not an error.
    expect(app(ChainVerifier::class)->verify($this->chain, -5)->fromSequence)->toBe(1);
});

it('reports gaps and hash mismatches with the numbers an operator needs', function (): void {
    $events = Chains::write(Chains::TENANT_A, 4);

    AppendOnly::bypass(function () use ($events): void {
        DB::table('audit_events')->where('id', $events[1]->id)->delete();
        DB::table('audit_events')->where('id', $events[3]->id)->update(['action' => 'tampered.action']);
    });

    $report = Audit::verifyTenant(Chains::TENANT_A);
    $gap = violation($report->violations, ViolationReason::SequenceGap);
    $link = violation($report->violations, ViolationReason::PreviousHashMismatch);
    $hash = violation($report->violations, ViolationReason::EventHashMismatch);

    expect($gap->sequence)->toBe(3)
        ->and($gap->details['expected_sequence'])->toBe(2)
        ->and($link->sequence)->toBe(3)
        ->and($link->details['expected_previous_hash'])->toBe($events[0]->event_hash)
        ->and($link->details['stored_previous_hash'])->toBe($events[1]->event_hash)
        ->and($hash->sequence)->toBe(4)
        ->and($hash->eventId)->toBe($events[3]->id)
        ->and($hash->details['stored_event_hash'])->toBe($events[3]->event_hash)
        ->and($hash->details['expected_event_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash->details['expected_event_hash'])->not->toBe($events[3]->event_hash)
        ->and($report->eventsChecked)->toBe(3)
        ->and($hash->describe())->toContain('event_hash_mismatch at sequence 4');
});

it('compares the head with what it saw, including a head that claims events for an empty chain', function (): void {
    $events = Chains::write(Chains::TENANT_A, 2);

    AppendOnly::bypass(fn () => DB::table('audit_chain_heads')->update(['last_sequence' => 5, 'last_hash' => str_repeat('f', 64)]));

    $head = violation(Audit::verifyTenant(Chains::TENANT_A)->violations, ViolationReason::HeadMismatch);

    expect($head->sequence)->toBe(2)
        ->and($head->details)->toBe([
            'head_sequence' => 5,
            'head_hash' => str_repeat('f', 64),
            'last_seen_sequence' => 2,
            'last_seen_hash' => $events[1]->event_hash,
        ]);

    // A head pointing at sequence 0 with no events is fine; a head with a hash but no events is not.
    Audit::prepareChain(Chains::TENANT_B);

    expect(Audit::verifyTenant(Chains::TENANT_B)->isIntact())->toBeTrue();

    AppendOnly::bypass(fn () => DB::table('audit_chain_heads')->where('chain_key', 'tenant:'.Chains::TENANT_B)->update(['last_sequence' => 1, 'last_hash' => str_repeat('a', 64)]));

    $phantom = violation(Audit::verifyTenant(Chains::TENANT_B)->violations, ViolationReason::HeadMismatch);

    expect($phantom->details['last_seen_sequence'])->toBeNull()->and($phantom->details['head_sequence'])->toBe(1);

    // Events without a head row at all (someone deleted it): reported as head mismatch, not as intact.
    AppendOnly::bypass(fn () => DB::table('audit_chain_heads')->where('chain_key', $this->chain->toString())->delete());

    expect(violation(Audit::verifyTenant(Chains::TENANT_A)->violations, ViolationReason::HeadMismatch)->details)->toBe(['reason' => 'head row missing']);
});

it('rejects anchors whose signature does not verify or that point past the chain', function (): void {
    $events = Chains::write(Chains::TENANT_A, 2);
    $store = app(AnchorStore::class);

    // An anchor with a forged signature over the right hash.
    $store->store(new Anchor($this->chain, 2, $events[1]->event_hash, new Signature('k1', str_repeat('e', 64)), new DateTimeImmutable('2026-09-22T09:00:00Z')));

    $forged = violation(Audit::verifyTenant(Chains::TENANT_A)->violations, ViolationReason::AnchorMismatch);

    expect($forged->sequence)->toBe(2)
        ->and($forged->eventId)->toBe($events[1]->id)
        ->and($forged->details)->toBe(['reason' => 'anchor signature '.SignatureStatus::Invalid->value]);

    // An anchor with an unknown key id is just as invalid.
    $store->store(new Anchor($this->chain, 2, $events[1]->event_hash, new Signature('k9', str_repeat('e', 64)), new DateTimeImmutable('2026-09-22T09:01:00Z')));

    expect(violation(Audit::verifyTenant(Chains::TENANT_A)->violations, ViolationReason::AnchorMismatch)->details['reason'])->toBe('anchor signature '.SignatureStatus::UnknownKey->value);

    // A genuine anchor written by the anchorer clears it again (latest wins).
    app(Anchorer::class)->anchor($this->chain, force: true);

    expect(Audit::verifyTenant(Chains::TENANT_A)->isIntact())->toBeTrue();

    // An anchor ahead of the chain: details name the anchor and what was seen.
    $store->store(new Anchor($this->chain, 7, str_repeat('b', 64), null, new DateTimeImmutable('2026-09-22T09:05:00.250000Z')));

    $ahead = violation(Audit::verifyTenant(Chains::TENANT_A)->violations, ViolationReason::AnchorAhead);

    expect($ahead->sequence)->toBe(7)
        ->and($ahead->details['last_seen_sequence'])->toBe(2)
        ->and($ahead->details['anchored_at'])->toBe('2026-09-22T09:05:00.250+00:00');

    // A ranged verification only consults anchors at or below its upper bound.
    expect(Audit::verifyTenant(Chains::TENANT_A, 1, 2)->isIntact())->toBeTrue();
});

it('honours the upper bound and never reads past it', function (): void {
    $events = Chains::write(Chains::TENANT_A, 4);

    AppendOnly::bypass(fn () => DB::table('audit_events')->where('id', $events[3]->id)->update(['action' => 'tampered.action']));

    $bounded = Audit::verifyTenant(Chains::TENANT_A, 1, 3);
    $full = Audit::verifyTenant(Chains::TENANT_A);

    expect($bounded->isIntact())->toBeTrue()
        ->and($bounded->eventsChecked)->toBe(3)
        ->and($bounded->toSequence)->toBe(3)
        ->and($bounded->lastHash)->toBe($events[2]->event_hash)
        ->and($full->isIntact())->toBeFalse()
        ->and($full->eventsChecked)->toBe(4);
});

it('reports unsigned chains as such and keeps verifying hashes without a key', function (): void {
    Chains::write(Chains::TENANT_A, 2);

    config()->set('audit.signing.keys', '');
    config()->set('audit.signing.active_key', '');
    foreach ([Efnext\Security\Audit\Signing\Keyring::class, Efnext\Security\Audit\Contracts\Signer::class, ChainVerifier::class, Efnext\Security\Audit\Contracts\AuditWriter::class, Efnext\Security\Audit\AuditManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
    Audit::clearResolvedInstance(Efnext\Security\Audit\AuditManager::class);

    $report = Audit::verifyTenant(Chains::TENANT_A);

    expect($report->isIntact())->toBeTrue()->and($report->signatures)->toBe(SignatureStatus::NotConfigured);

    // Events written without a key carry no signature; the verifier still checks their hashes.
    [$unsigned] = Chains::write(Chains::TENANT_A, 1);

    expect($unsigned->key_id)->toBeNull()->and($unsigned->signature)->toBeNull()->and($unsigned->sequence)->toBe(3);

    AppendOnly::bypass(fn () => DB::table('audit_events')->where('id', $unsigned->id)->update(['outcome' => 'denied']));

    expect(violation(Audit::verifyTenant(Chains::TENANT_A)->violations, ViolationReason::EventHashMismatch)->sequence)->toBe(3);
});
