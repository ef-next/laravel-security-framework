<?php

declare(strict_types=1);

use Efnext\Security\Addressing\Exceptions\InvalidPublicId;
use Efnext\Security\Addressing\Identifiers\PublicId;

it('parses a canonical public id', function (): void {
    $id = PublicId::fromString('dom_7f3k9zq2m4x8c1bt');

    expect($id->prefix)->toBe('dom')
        ->and($id->body)->toBe('7f3k9zq2m4x8c1bt')
        ->and($id->toString())->toBe('dom_7f3k9zq2m4x8c1bt')
        ->and((string) $id)->toBe('dom_7f3k9zq2m4x8c1bt')
        ->and(json_encode($id))->toBe('"dom_7f3k9zq2m4x8c1bt"')
        ->and($id->hasPrefix('dom'))->toBeTrue()
        ->and($id->hasPrefix('usr'))->toBeFalse();
});

it('accepts prefixes with digits and up to twelve characters', function (): void {
    expect(PublicId::isValid('a1_abcdefgh'))->toBeTrue()
        ->and(PublicId::isValid('abcdefghijkl_abcdefgh'))->toBeTrue()
        ->and(PublicId::isValid('abcdefghijklm_abcdefgh'))->toBeFalse();
});

it('rejects anything that is not canonical', function (string $value): void {
    expect(PublicId::isValid($value))->toBeFalse()
        ->and(PublicId::tryFromString($value))->toBeNull()
        ->and(fn () => PublicId::fromString($value))->toThrow(InvalidPublicId::class);
})->with([
    'uppercase prefix' => 'DOM_7f3k9zq2m4x8c1bt',
    'uppercase body' => 'dom_7F3K9ZQ2M4X8C1BT',
    'leading whitespace' => ' dom_7f3k9zq2m4x8c1bt',
    'trailing whitespace' => 'dom_7f3k9zq2m4x8c1bt ',
    'missing separator' => 'dom7f3k9zq2m4x8c1bt',
    'empty body' => 'dom_',
    'short body' => 'dom_abc',
    'single char prefix' => 'd_7f3k9zq2m4x8c1bt',
    'prefix starting with digit' => '1om_7f3k9zq2m4x8c1bt',
    'hyphen in body' => 'dom_7f3k-zq2m4x8c1bt',
    'sql fragment' => "dom_abc' OR 1=1",
    'unicode' => 'dom_7f3k9zq2m4x8c1bé',
    'empty' => '',
]);

it('treats a null value as invalid without throwing', function (): void {
    expect(PublicId::tryFromString(null))->toBeNull()
        ->and(PublicId::isValid(null))->toBeFalse();
});

it('builds from parts and validates them', function (): void {
    expect(PublicId::of('usr', 'abcdefgh12345678')->toString())->toBe('usr_abcdefgh12345678')
        ->and(fn () => PublicId::of('Usr', 'abcdefgh12345678'))->toThrow(InvalidPublicId::class)
        ->and(fn () => PublicId::of('usr', 'ABC'))->toThrow(InvalidPublicId::class);
});

it('compares by value', function (): void {
    $a = PublicId::fromString('dom_7f3k9zq2m4x8c1bt');

    expect($a->equals(PublicId::fromString('dom_7f3k9zq2m4x8c1bt')))->toBeTrue()
        ->and($a->equals(PublicId::fromString('dom_7f3k9zq2m4x8c1bx')))->toBeFalse()
        ->and($a->equals(null))->toBeFalse();
});
