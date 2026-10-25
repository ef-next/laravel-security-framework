<?php

declare(strict_types=1);

use Efnext\Security\Audit\Sanitizing\DefaultMetadataSanitizer;
use Efnext\Security\Audit\Sanitizing\KeyMatcher;
use Efnext\Security\Audit\Sanitizing\SanitizerConfig;
use Efnext\Security\Audit\Sanitizing\SecretDetector;

function sanitizer(?string $pepper = 'pepper-0123456789-abcdefghijklmnop'): DefaultMetadataSanitizer
{
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__, 2).'/config/audit.php';

    return new DefaultMetadataSanitizer(SanitizerConfig::fromArray($config['sanitizer'], $pepper));
}

it('tokenises keys on separators and camel case', function (): void {
    expect(KeyMatcher::tokenize('passwordConfirmation'))->toBe(['password', 'confirmation'])
        ->and(KeyMatcher::tokenize('x-api-key'))->toBe(['x', 'api', 'key'])
        ->and(KeyMatcher::tokenize('HTTPResponseCode'))->toBe(['http', 'response', 'code'])
        ->and(KeyMatcher::tokenize('session_count'))->toBe(['session', 'count'])
        ->and(KeyMatcher::tokenize('__token__'))->toBe(['token']);
});

it('redacts secret keys and keeps look-alikes', function (): void {
    $out = sanitizer()->sanitize([
        'password' => 'hunter2',
        'passwordConfirmation' => 'hunter2',
        'access_token' => 'abc',
        'x-api-key' => 'abc',
        'Authorization' => 'Bearer abc',
        'session_id' => 'abc',
        'client_secret' => 'abc',
        'author' => 'Enrico',
        'oauth_provider' => 'github',
        'bypass_reason' => 'incident',
        'passport_number' => 'C01X00T47',
        'session_count' => 3,
        'compass_heading' => 180,
        'token_type' => 'bearer',
        'note' => 'ok',
    ]);

    foreach (['password', 'passwordConfirmation', 'access_token', 'x-api-key', 'Authorization', 'session_id', 'client_secret'] as $key) {
        expect($out[$key])->toBe('[REDACTED]', $key);
    }

    expect($out['author'])->toBe('Enrico')
        ->and($out['oauth_provider'])->toBe('github')
        ->and($out['bypass_reason'])->toBe('incident')
        ->and($out['passport_number'])->toBe('C01X00T47')
        ->and($out['session_count'])->toBe(3)
        ->and($out['compass_heading'])->toBe(180)
        ->and($out['token_type'])->toBe('bearer')
        ->and($out['note'])->toBe('ok');
});

it('redacts secret-shaped values regardless of the key', function (): void {
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

    $out = sanitizer()->sanitize([
        'note' => $jwt,
        'header' => 'Bearer '.$jwt,
        'key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIE",
        'card' => '4111 1111 1111 1111',
        'aws' => 'AKIAIOSFODNN7EXAMPLE',
        'amount' => '4111 1111 1111 1112',
        'prose' => 'the quick brown fox',
    ]);

    expect($out['note'])->toBe('[REDACTED]')
        ->and($out['header'])->toBe('[REDACTED]')
        ->and($out['key'])->toBe('[REDACTED]')
        ->and($out['card'])->toBe('[REDACTED]')
        ->and($out['aws'])->toBe('[REDACTED]')
        ->and($out['amount'])->toBe('4111 1111 1111 1112')
        ->and($out['prose'])->toBe('the quick brown fox')
        ->and((new SecretDetector)->looksSecret('4111111111111111'))->toBe('card_number');
});

it('redacts secrets that are longer than the scan window instead of storing a truncated prefix', function (): void {
    $segment = fn (int $bytes): string => substr(str_repeat('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_', 400), 0, $bytes);
    $bigJwt = 'eyJhbGciOiJIUzI1NiJ9.'.$segment(20_000).'.'.$segment(43);   // payload far beyond 8 KiB
    $bigPem = "-----BEGIN RSA PRIVATE KEY-----\n".chunk_split($segment(12_000), 64).'-----END RSA PRIVATE KEY-----';
    $bearer = 'Bearer '.$segment(10_000);
    $lateAws = str_repeat('lorem ipsum ', 1_000).' key=AKIAIOSFODNN7EXAMPLE tail';           // token after byte 8192
    $straddling = str_repeat('x', SecretDetector::WINDOW - 10).' AKIAIOSFODNN7EXAMPLE '.str_repeat('y', 500);
    $embeddedPem = str_repeat('lorem ipsum ', 2_000)."\n-----BEGIN EC PRIVATE KEY-----\nMHc";
    $longProse = str_repeat('the quick brown fox jumps over the lazy dog. ', 500);        // 22 KB of harmless text
    $huge = str_repeat('a', SecretDetector::HARD_LIMIT + 1);

    $out = sanitizer()->sanitize(compact('bigJwt', 'bigPem', 'bearer', 'lateAws', 'straddling', 'embeddedPem', 'longProse', 'huge'));

    expect($out['bigJwt'])->toBe('[REDACTED]')
        ->and($out['bigPem'])->toBe('[REDACTED]')
        ->and($out['bearer'])->toBe('[REDACTED]')
        ->and($out['lateAws'])->toBe('[REDACTED]')
        ->and($out['straddling'])->toBe('[REDACTED]')
        ->and($out['embeddedPem'])->toBe('[REDACTED]')
        ->and($out['huge'])->toBe('[REDACTED]')
        ->and($out['longProse'])->toEndWith('…[truncated]')
        ->and($out['longProse'])->toStartWith('the quick brown fox')
        ->and((new SecretDetector)->looksSecret($bigJwt))->toBe('jwt')
        ->and((new SecretDetector)->looksSecret($huge))->toBe('oversized')
        ->and((new SecretDetector)->looksSecret($longProse))->toBeNull();
});

it('pseudonymises identifiers with a keyed hash and never emits a plain hash', function (): void {
    $out = sanitizer()->sanitize(['email' => 'CEO@Acme.example', 'userName' => 'Admin', 'actor_email' => 'a@b.c', 'email_verified' => true]);

    expect($out)->not->toHaveKey('email')
        ->and($out['email_hash'])->toBe(hash_hmac('sha256', 'ceo@acme.example', 'pepper-0123456789-abcdefghijklmnop'))
        ->and($out['email_hash'])->not->toBe(hash('sha256', 'ceo@acme.example'))
        ->and($out)->toHaveKey('userName_hash')
        ->and($out)->toHaveKey('actor_email_hash')
        ->and($out['email_verified'])->toBeTrue()
        ->and(sanitizer(null)->sanitize(['email' => 'a@b.c'])['email_hash'])->toBe('[REDACTED]');
});

it('bounds depth, key count and string length with visible markers', function (): void {
    $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'deep']]]]]]];
    $wide = [];

    for ($i = 0; $i < 150; $i++) {
        $wide['k'.$i] = $i;
    }

    $out = sanitizer()->sanitize(['deep' => $deep, 'wide' => $wide, 'long' => str_repeat('x', 2000), 'list' => range(1, 150)]);

    expect($out['deep']['a']['b']['c']['d']['e'])->toBe(['_depth_limited' => true])
        ->and($out['wide'])->toHaveKey('_truncated')
        ->and(count($out['wide']))->toBe(101)
        ->and($out['long'])->toEndWith('…[truncated]')
        ->and(mb_strlen($out['long']))->toBe(1024 + mb_strlen('…[truncated]'))
        ->and(end($out['list']))->toBe('_truncated');
});

it('normalises exotic values', function (): void {
    $out = sanitizer()->sanitize([
        'when' => new DateTimeImmutable('2026-09-20T10:00:00+02:00'),
        'enum' => Efnext\Security\Audit\Enums\Outcome::Denied,
        'float' => 1.5,
        'inf' => INF,
        'obj' => new stdClass,
        'null' => null,
    ]);

    expect($out['when'])->toBe('2026-09-20T10:00:00.000+02:00')
        ->and($out['enum'])->toBe('denied')
        ->and($out['float'])->toBe(1.5)
        ->and($out['inf'])->toBe('INF')
        ->and($out['obj'])->toBe('[stdClass]')
        ->and($out['null'])->toBeNull();
});
