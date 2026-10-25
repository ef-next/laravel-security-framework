<?php

declare(strict_types=1);

use Efnext\Security\Audit\Anchoring\Anchorer;
use Efnext\Security\Audit\Anchoring\CompositeAnchorStore;
use Efnext\Security\Audit\Anchoring\FilesystemAnchorStore;
use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Audit\Tests\Fixtures\AppendOnly;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Tenancy::clear();
});

it('anchors changed chains and skips unchanged ones', function (): void {
    Chains::write(Chains::TENANT_A, 2);

    $this->artisan('audit:anchor')->expectsOutputToContain('anchored #2 (signed k1)')->assertSuccessful();
    $this->artisan('audit:anchor')->expectsOutputToContain('unchanged')->assertSuccessful();

    Chains::write(Chains::TENANT_A, 1);
    $this->artisan('audit:anchor')->expectsOutputToContain('anchored #3')->assertSuccessful();

    expect(DB::table('audit_anchors')->count())->toBe(2)
        ->and(app(AnchorStore::class)->latest(ChainKey::fromString(Chains::TENANT_A))?->sequence)->toBe(3)
        ->and(fn () => DB::table('audit_anchors')->delete())->toThrow(Illuminate\Database\QueryException::class);
});

it('writes anchors to a filesystem disk and reads the latest back', function (): void {
    Storage::fake('anchors');
    $store = new FilesystemAnchorStore(Storage::disk('anchors'), 'anchors');
    $anchorer = new Anchorer(app(Illuminate\Database\ConnectionResolverInterface::class), $store, app(Efnext\Security\Audit\Contracts\Signer::class));

    Chains::write(Chains::TENANT_A, 2);
    $anchorer->anchor(ChainKey::fromString(Chains::TENANT_A));
    Chains::write(Chains::TENANT_A, 1);
    $anchorer->anchor(ChainKey::fromString(Chains::TENANT_A));

    $chain = ChainKey::fromString(Chains::TENANT_A);

    expect(count(Storage::disk('anchors')->files('anchors/tenant_'.Chains::TENANT_A)))->toBe(2)
        ->and($store->latest($chain)?->sequence)->toBe(3)
        ->and($store->latest($chain, 2)?->sequence)->toBe(2)
        ->and($store->latest($chain)?->signature?->keyId)->toBe('k1');
});

it('fans out to several stores and reads from the first that answers', function (): void {
    Storage::fake('anchors');
    $fs = new FilesystemAnchorStore(Storage::disk('anchors'));
    $composite = new CompositeAnchorStore($fs, app(AnchorStore::class));
    $anchorer = new Anchorer(app(Illuminate\Database\ConnectionResolverInterface::class), $composite, app(Efnext\Security\Audit\Contracts\Signer::class));

    Chains::write(Chains::TENANT_A, 1);
    $anchorer->anchor(ChainKey::fromString(Chains::TENANT_A));

    expect(DB::table('audit_anchors')->count())->toBe(1)
        ->and($fs->latest(ChainKey::fromString(Chains::TENANT_A)))->not->toBeNull()
        ->and($composite->name())->toBe('filesystem+database');
});

it('verifies from the console with meaningful exit codes', function (): void {
    [$first] = Chains::write(Chains::TENANT_A, 2);
    Chains::write(Chains::TENANT_B, 1);

    $this->artisan('audit:verify')->expectsOutputToContain('intact')->assertSuccessful();
    $this->artisan('audit:verify', ['--chain' => [Chains::TENANT_B], '--json' => true])->assertSuccessful();

    AppendOnly::bypass(fn () => DB::table('audit_events')->where('id', $first->id)->update(['actor_id' => 'x']));

    $this->artisan('audit:verify')->expectsOutputToContain('VIOLATIONS')->assertFailed();
    $this->artisan('audit:verify', ['--chain' => [Chains::TENANT_B]])->assertSuccessful();
});

it('generates signing keys', function (): void {
    $this->artisan('audit:key:generate', ['--id' => 'k2'])
        ->expectsOutputToContain('AUDIT_SIGNING_KEYS="k2:base64:')
        ->expectsOutputToContain('AUDIT_SIGNING_ACTIVE_KEY=k2')
        ->assertSuccessful();

    $this->artisan('audit:key:generate', ['--id' => 'bad id'])->assertFailed();
});
