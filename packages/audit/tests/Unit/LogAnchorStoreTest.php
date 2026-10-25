<?php

declare(strict_types=1);

use Efnext\Security\Audit\Anchoring\CompositeAnchorStore;
use Efnext\Security\Audit\Anchoring\LogAnchorStore;
use Efnext\Security\Audit\Anchoring\NullAnchorStore;
use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Audit\Records\Signature;
use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

it('emits one structured line per anchor and never reads back', function (): void {
    $logger = new RecordingLogger;
    $store = new LogAnchorStore($logger);
    $chain = ChainKey::fromString('9c5f3e2a-7d1b-4e8f-a2c6-0b1d2e3f4a5b');
    $anchor = new Anchor($chain, 42, str_repeat('a', 64), new Signature('k1', str_repeat('b', 64)), new DateTimeImmutable('2026-09-22T10:00:00.123456Z'));

    $store->store($anchor);

    expect($store->name())->toBe('log')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('info')
        ->and($logger->records[0]['message'])->toBe('audit.anchor')
        ->and($logger->records[0]['context'])->toBe([
            'chain_key' => 'tenant:9c5f3e2a-7d1b-4e8f-a2c6-0b1d2e3f4a5b',
            'sequence' => 42,
            'event_hash' => str_repeat('a', 64),
            'key_id' => 'k1',
            'signature' => str_repeat('b', 64),
            'anchored_at' => '2026-09-22T10:00:00.123456Z',
        ])
        ->and($store->latest($chain))->toBeNull()
        ->and($store->latest($chain, 42))->toBeNull();
});

it('is write-only inside a composite: writes fan out, reads come from a readable store', function (): void {
    $logger = new RecordingLogger;
    $composite = new CompositeAnchorStore(new LogAnchorStore($logger), new NullAnchorStore);
    $chain = ChainKey::global();

    $composite->store(new Anchor($chain, 1, str_repeat('c', 64), null, new DateTimeImmutable('2026-09-22T10:00:00Z')));

    expect($logger->records)->toHaveCount(1)
        ->and($composite->latest($chain))->toBeNull()
        ->and($composite->name())->toBe('log+none');
});

it('is built from the configuration with an optional dedicated channel', function (): void {
    config()->set('audit.anchoring.stores', ['log']);
    config()->set('audit.anchoring.log.channel', 'stack');
    app()->forgetInstance(AnchorStore::class);

    expect(app(AnchorStore::class))->toBeInstanceOf(LogAnchorStore::class);

    config()->set('audit.anchoring.stores', ['log', 'database']);
    app()->forgetInstance(AnchorStore::class);

    $store = app(AnchorStore::class);

    expect($store)->toBeInstanceOf(CompositeAnchorStore::class)
        ->and($store->name())->toBe('log+database');
});
