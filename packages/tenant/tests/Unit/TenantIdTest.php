<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Exceptions\InvalidTenantId;

it('accepts uuids, ulids and simple keys', function (string $value): void {
    expect(TenantId::fromString($value)->toString())->toBe($value)
        ->and((string) TenantId::fromString($value))->toBe($value)
        ->and(json_encode(TenantId::fromString($value)))->toBe(json_encode($value));
})->with([
    '9c5f3e2a-7d1b-4e8f-a2c6-0b1d2e3f4a5b',
    '01J8ZQ0Y3K5X6W7V8T9S0R1P2N',
    'tenant-123',
    'acme_corp',
    '42',
]);

it('rejects empty, whitespace-wrapped, oversized and control-character values', function (string $value): void {
    expect(TenantId::tryFromString($value))->toBeNull()
        ->and(fn () => TenantId::fromString($value))->toThrow(InvalidTenantId::class);
})->with([
    'empty' => '',
    'space' => ' ',
    'leading space' => ' abc',
    'trailing newline' => "abc\n",
    'leading dash' => '-abc',
    'too long' => str_repeat('a', 192),
    'sql' => "abc' OR 1=1",
    'unicode' => 'tenänt',
]);

it('treats non-strings as invalid', function (): void {
    expect(TenantId::tryFromString(null))->toBeNull()
        ->and(TenantId::tryFromString(42))->toBeNull()
        ->and(TenantId::tryFromString(['a']))->toBeNull();
});

it('compares in constant time by value', function (): void {
    $a = TenantId::fromString('tenant-a');

    expect($a->equals(TenantId::fromString('tenant-a')))->toBeTrue()
        ->and($a->equals(TenantId::fromString('tenant-b')))->toBeFalse()
        ->and($a->equals(null))->toBeFalse();
});
