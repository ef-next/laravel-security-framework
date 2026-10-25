<?php

declare(strict_types=1);

use Efnext\Security\Audit\Anchoring\Anchorer;
use Efnext\Security\Audit\Contracts\ChainVerifier;
use Efnext\Security\Audit\Enums\SignatureStatus;
use Efnext\Security\Audit\Enums\ViolationReason;
use Efnext\Security\Audit\Events\ChainViolationDetected;
use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Audit\Hashing\EventHasher;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Audit\Records\Violation;
use Efnext\Security\Audit\Tests\Fixtures\AppendOnly;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Tenancy::clear();
    $this->chain = ChainKey::fromString(Chains::TENANT_A);
});

function reasons(array $violations): array
{
    return array_values(array_unique(array_map(static fn (Violation $v): string => $v->reason->value, $violations)));
}

it('reports an intact chain', function (): void {
    Chains::write(Chains::TENANT_A, 5);

    $report = Audit::verifyTenant(Chains::TENANT_A);

    expect($report->isIntact())->toBeTrue()
        ->and($report->eventsChecked)->toBe(5)
        ->and($report->signatures)->toBe(SignatureStatus::Valid)
        ->and($report->lastHash)->toBe(DB::table('audit_chain_heads')->value('last_hash'));
});

it('detects a changed actor', function (): void {
    Event::fake([ChainViolationDetected::class]);
    [$first] = Chains::write(Chains::TENANT_A, 3);

    AppendOnly::bypass(fn () => DB::table('audit_events')->where('id', $first->id)->update(['actor_id' => 'someone-else']));

    $report = Audit::verifyTenant(Chains::TENANT_A);

    expect($report->isIntact())->toBeFalse()
        ->and(reasons($report->violations))->toBe([ViolationReason::EventHashMismatch->value])
        ->and($report->violations[0]->sequence)->toBe(1);

    Event::assertDispatched(ChainViolationDetected::class);
});

it('detects changed metadata, outcome, action, resource and time', function (string $column, mixed $value): void {
    [$event] = Chains::write(Chains::TENANT_A, 2);

    AppendOnly::bypass(fn () => DB::table('audit_events')->where('id', $event->id)->update([$column => $value]));

    expect(reasons(Audit::verifyTenant(Chains::TENANT_A)->violations))->toContain(ViolationReason::EventHashMismatch->value);
})->with([
    ['metadata', '{"step":99}'],
    ['outcome', 'denied'],
    ['action', 'domain.deleted'],
    ['resource_id', 'dom_0000000000000009'],
    ['occurred_at_us', 1],
    ['ip_address', '198.51.100.1'],
    ['request_id', 'forged'],
]);

it('detects a deleted event in the middle of the chain', function (): void {
    [, $second] = Chains::write(Chains::TENANT_A, 4);

    AppendOnly::bypass(fn () => DB::table('audit_events')->where('id', $second->id)->delete());

    $report = Audit::verifyTenant(Chains::TENANT_A);

    expect($report->eventsChecked)->toBe(3)
        ->and(reasons($report->violations))->toContain(ViolationReason::SequenceGap->value, ViolationReason::PreviousHashMismatch->value);
});

it('detects a deleted tail through the chain head', function (): void {
    [, , $third] = Chains::write(Chains::TENANT_A, 3);

    AppendOnly::bypass(fn () => DB::table('audit_events')->where('id', $third->id)->delete());

    expect(reasons(Audit::verifyTenant(Chains::TENANT_A)->violations))->toBe([ViolationReason::HeadMismatch->value]);
});

it('detects a consistently rewritten chain through signatures', function (): void {
    $events = Chains::write(Chains::TENANT_A, 3);
    $hasher = app(EventHasher::class);

    // Attacker with database access but without the signing key: rewrite the
    // actor everywhere and recompute every hash so the plain chain stays valid.
    AppendOnly::bypass(function () use ($events, $hasher): void {
        $previous = null;

        foreach ($events as $event) {
            $fresh = $event->fresh();
            $record = $fresh->hashableRecord();
            $record['actor']['id'] = 'someone-else';
            $record['previous_hash'] = $previous;
            $hash = $hasher->hash($record);

            DB::table('audit_events')->where('id', $event->id)->update(['actor_id' => 'someone-else', 'previous_hash' => $previous, 'event_hash' => $hash]);
            $previous = $hash;
        }

        DB::table('audit_chain_heads')->update(['last_hash' => $previous]);
    });

    $report = Audit::verifyTenant(Chains::TENANT_A);

    expect(reasons($report->violations))->toBe([ViolationReason::SignatureInvalid->value])
        ->and($report->signatures)->toBe(SignatureStatus::Invalid);
});

it('detects a stripped signature and an unknown key', function (): void {
    [$first, $second] = Chains::write(Chains::TENANT_A, 2);

    AppendOnly::bypass(function () use ($first, $second): void {
        DB::table('audit_events')->where('id', $first->id)->update(['signature' => null, 'key_id' => null]);
        DB::table('audit_events')->where('id', $second->id)->update(['key_id' => 'k9']);
    });

    expect(reasons(Audit::verifyTenant(Chains::TENANT_A)->violations))
        ->toBe([ViolationReason::SignatureMissing->value, ViolationReason::SignatureKeyUnknown->value]);
});

it('uses anchors to detect a tail removed together with a rewritten head', function (): void {
    [, , $third] = Chains::write(Chains::TENANT_A, 3);
    app(Anchorer::class)->anchor($this->chain);

    AppendOnly::bypass(function () use ($third): void {
        DB::table('audit_events')->where('id', $third->id)->delete();
        $second = DB::table('audit_events')->where('sequence', 2)->first();
        DB::table('audit_chain_heads')->update(['last_sequence' => 2, 'last_hash' => $second->event_hash]);
    });

    expect(reasons(Audit::verifyTenant(Chains::TENANT_A)->violations))->toBe([ViolationReason::AnchorAhead->value]);
});

it('uses anchors to detect a rewritten anchored event', function (): void {
    $events = Chains::write(Chains::TENANT_A, 2);
    app(Anchorer::class)->anchor($this->chain);
    $hasher = app(EventHasher::class);

    AppendOnly::bypass(function () use ($events, $hasher): void {
        $last = $events[1]->fresh();
        $record = $last->hashableRecord();
        $record['outcome'] = 'denied';
        $hash = $hasher->hash($record);
        DB::table('audit_events')->where('id', $last->id)->update(['outcome' => 'denied', 'event_hash' => $hash]);
        DB::table('audit_chain_heads')->update(['last_hash' => $hash]);
    });

    expect(reasons(Audit::verifyTenant(Chains::TENANT_A)->violations))->toContain(ViolationReason::AnchorMismatch->value);
});

it('verifies in chunks and ranges', function (): void {
    config()->set('audit.verification.chunk_size', 2);
    app()->forgetInstance(ChainVerifier::class);
    app()->forgetInstance(Efnext\Security\Audit\AuditManager::class);
    Audit::clearResolvedInstance(Efnext\Security\Audit\AuditManager::class);

    Chains::write(Chains::TENANT_A, 7);

    $full = Audit::verifyTenant(Chains::TENANT_A);
    $range = Audit::verifyTenant(Chains::TENANT_A, 3, 5);
    $orphan = Audit::verifyTenant(Chains::TENANT_A, 9);

    expect($full->isIntact())->toBeTrue()->and($full->eventsChecked)->toBe(7)
        ->and($range->isIntact())->toBeTrue()->and($range->eventsChecked)->toBe(3)
        ->and(reasons($orphan->violations))->toBe([ViolationReason::MissingPredecessor->value]);
});

it('lists chains and reports empty chains as intact', function (): void {
    Chains::write(Chains::TENANT_A, 1);
    Chains::write(Chains::TENANT_B, 1);

    $verifier = app(ChainVerifier::class);

    expect(array_map(static fn (ChainKey $k): string => $k->toString(), $verifier->chains()))
        ->toBe(['tenant:'.Chains::TENANT_B, 'tenant:'.Chains::TENANT_A])
        ->and($verifier->verify(ChainKey::global())->isIntact())->toBeTrue()
        ->and($verifier->verify(ChainKey::global())->eventsChecked)->toBe(0);
});
