<?php

declare(strict_types=1);

use Efnext\Security\Addressing\Generation\Encoding;

it('encodes ten bytes to sixteen crockford base32 characters', function (): void {
    $encoded = Encoding::Base32->encode(random_bytes(10));

    expect($encoded)->toHaveLength(16)
        ->and($encoded)->toMatch('/^[0-9a-hjkmnp-tv-z]{16}$/')
        ->and(Encoding::Base32->length(10))->toBe(16);
});

it('encodes known vectors', function (): void {
    expect(Encoding::Base32->encode("\x00\x00\x00\x00\x00"))->toBe('00000000')
        ->and(Encoding::Base32->encode("\xff\xff\xff\xff\xff"))->toBe('zzzzzzzz')
        ->and(Encoding::Hex->encode("\x00\xff\x10"))->toBe('00ff10')
        ->and(Encoding::Hex->length(8))->toBe(16);
});

it('pads a trailing partial group', function (): void {
    expect(Encoding::Base32->encode("\xff"))->toBe('zw')
        ->and(Encoding::Base32->length(1))->toBe(2);
});

it('never contains ambiguous characters', function (): void {
    $sample = '';

    for ($i = 0; $i < 200; $i++) {
        $sample .= Encoding::Base32->encode(random_bytes(10));
    }

    expect($sample)->not->toMatch('/[ilou]/');
});

it('resolves encodings by name', function (): void {
    expect(Encoding::fromName('base32'))->toBe(Encoding::Base32)
        ->and(Encoding::fromName(' HEX '))->toBe(Encoding::Hex)
        ->and(fn () => Encoding::fromName('base64'))->toThrow(InvalidArgumentException::class);
});
