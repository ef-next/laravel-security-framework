<?php

declare(strict_types=1);

use Efnext\Security\Addressing\Exceptions\InvalidGlobalObjectId;
use Efnext\Security\Addressing\Identifiers\GlobalObjectId;
use Efnext\Security\Addressing\Identifiers\PublicId;

it('parses and renders a canonical global object id', function (): void {
    $id = GlobalObjectId::fromString('example-service:domain:dom_7f3k9zq2m4x8c1bt');

    expect($id->service)->toBe('example-service')
        ->and($id->resourceType)->toBe('domain')
        ->and($id->publicId->toString())->toBe('dom_7f3k9zq2m4x8c1bt')
        ->and($id->toString())->toBe('example-service:domain:dom_7f3k9zq2m4x8c1bt')
        ->and(json_encode($id))->toBe('"example-service:domain:dom_7f3k9zq2m4x8c1bt"')
        ->and($id->is('example-service'))->toBeTrue()
        ->and($id->is('example-service', 'domain'))->toBeTrue()
        ->and($id->is('example-service', 'tenant'))->toBeFalse()
        ->and($id->is('risk-engine'))->toBeFalse();
});

it('never upper-cases anything', function (): void {
    $id = GlobalObjectId::of('svc', 'tenant', PublicId::fromString('ten_abcdefgh12345678'));

    expect($id->toString())->toBe('svc:tenant:ten_abcdefgh12345678');
});

it('rejects malformed values', function (string $value): void {
    expect(GlobalObjectId::isValid($value))->toBeFalse()
        ->and(GlobalObjectId::tryFromString($value))->toBeNull()
        ->and(GlobalObjectId::tryFromString(null))->toBeNull()
        ->and(fn () => GlobalObjectId::fromString($value))->toThrow(InvalidGlobalObjectId::class);
})->with([
    'uppercase service' => 'Identity:domain:dom_7f3k9zq2m4x8c1bt',
    'uppercase public id' => 'identity:domain:DOM_7F3K9ZQ2M4X8C1BT',
    'two segments' => 'identity:dom_7f3k9zq2m4x8c1bt',
    'four segments' => 'identity:domain:dom_7f3k9zq2m4x8c1bt:extra',
    'empty service' => ':domain:dom_7f3k9zq2m4x8c1bt',
    'space in public id' => 'identity:domain:dom_abc OR 1=1',
    'invalid public id' => 'identity:domain:not-a-public-id',
    'whitespace' => ' identity:domain:dom_7f3k9zq2m4x8c1bt',
]);

it('validates segments when building from parts', function (): void {
    $publicId = PublicId::fromString('dom_7f3k9zq2m4x8c1bt');

    expect(fn () => GlobalObjectId::of('Identity', 'domain', $publicId))->toThrow(InvalidGlobalObjectId::class)
        ->and(fn () => GlobalObjectId::of('identity', 'Domain', $publicId))->toThrow(InvalidGlobalObjectId::class)
        ->and(fn () => GlobalObjectId::of('identity', 'do:main', $publicId))->toThrow(InvalidGlobalObjectId::class);
});

it('compares by value', function (): void {
    $a = GlobalObjectId::fromString('svc:domain:dom_7f3k9zq2m4x8c1bt');

    expect($a->equals(GlobalObjectId::fromString('svc:domain:dom_7f3k9zq2m4x8c1bt')))->toBeTrue()
        ->and($a->equals(GlobalObjectId::fromString('other:domain:dom_7f3k9zq2m4x8c1bt')))->toBeFalse()
        ->and($a->equals(null))->toBeFalse();
});
