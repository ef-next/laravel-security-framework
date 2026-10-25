<?php

declare(strict_types=1);

use Efnext\Security\Audit\Enums\SignatureStatus;
use Efnext\Security\Audit\Exceptions\InvalidSigningKey;
use Efnext\Security\Audit\Signing\HmacSigner;
use Efnext\Security\Audit\Signing\Keyring;
use Efnext\Security\Audit\Signing\NullSigner;

function keyEntry(string $id): string
{
    return $id.':base64:'.base64_encode(random_bytes(32));
}

it('signs with the active key and verifies with any key in the ring', function (): void {
    $signer = new HmacSigner(Keyring::fromString(keyEntry('k1').', '.keyEntry('k2'), 'k2'));
    $hash = hash('sha256', 'event');
    $signature = $signer->sign($hash);

    expect($signer->isConfigured())->toBeTrue()
        ->and($signature?->keyId)->toBe('k2')
        ->and($signer->verify($hash, 'k2', $signature?->value))->toBe(SignatureStatus::Valid)
        ->and($signer->verify(strrev($hash), 'k2', $signature?->value))->toBe(SignatureStatus::Invalid)
        ->and($signer->verify($hash, 'k9', $signature?->value))->toBe(SignatureStatus::UnknownKey)
        ->and($signer->verify($hash, null, null))->toBe(SignatureStatus::Invalid);
});

it('rejects weak or inconsistent key configuration', function (string $keys, string $active): void {
    expect(fn () => Keyring::fromString($keys, $active))->toThrow(InvalidSigningKey::class);
})->with([
    'short key' => ['k1:base64:'.base64_encode('short'), 'k1'],
    'bad base64' => ['k1:base64:@@@', 'k1'],
    'bad format' => ['k1=abc', 'k1'],
    'unknown active' => [keyEntry('k1'), 'k2'],
    'missing active' => [keyEntry('k1'), ''],
    'bad id' => ['bad id!:base64:'.base64_encode(random_bytes(32)), 'bad id!'],
]);

it('treats an empty configuration as unsigned', function (): void {
    $keyring = Keyring::fromString('', '');
    $signer = new NullSigner;

    expect($keyring->isEmpty())->toBeTrue()
        ->and($signer->isConfigured())->toBeFalse()
        ->and($signer->sign('x'))->toBeNull()
        ->and($signer->verify('x', 'k1', 'y'))->toBe(SignatureStatus::NotConfigured);
});

it('signs nothing and verifies nothing without an active key, even as an HMAC signer', function (): void {
    $signer = new HmacSigner(Keyring::fromString('', ''));

    expect($signer->isConfigured())->toBeFalse()
        ->and($signer->sign(str_repeat('a', 64)))->toBeNull()
        ->and($signer->verify(str_repeat('a', 64), 'k1', str_repeat('b', 64)))->toBe(SignatureStatus::NotConfigured)
        ->and($signer->verify(str_repeat('a', 64), null, null))->toBe(SignatureStatus::NotConfigured);
});
