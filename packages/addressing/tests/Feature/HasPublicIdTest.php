<?php

declare(strict_types=1);

use Efnext\Security\Addressing\Contracts\PublicIdGenerator;
use Efnext\Security\Addressing\Exceptions\InvalidPublicId;
use Efnext\Security\Addressing\Exceptions\PublicIdGenerationFailed;
use Efnext\Security\Addressing\Exceptions\PublicIdImmutable;
use Efnext\Security\Addressing\Generation\Encoding;
use Efnext\Security\Addressing\Generation\RandomPublicIdGenerator;
use Efnext\Security\Addressing\Identifiers\PublicId;
use Efnext\Security\Addressing\Tests\Fixtures\Document;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('documents', function (Blueprint $table): void {
        $table->id();
        $table->publicId();
        $table->string('title');
    });
});

it('assigns a public id with the model prefix on create', function (): void {
    $document = Document::query()->create(['title' => 'Hello']);

    expect($document->public_id)->toMatch('/^doc_[0-9a-hjkmnp-tv-z]{16}$/')
        ->and($document->publicId())->toBeInstanceOf(PublicId::class)
        ->and($document->publicId()->prefix)->toBe('doc')
        ->and($document->getRouteKey())->toBe($document->public_id);
});

it('keeps a valid explicitly provided public id', function (): void {
    $document = new Document(['title' => 'Given']);
    $document->public_id = 'doc_abcdefgh12345678';
    $document->save();

    expect($document->fresh()?->public_id)->toBe('doc_abcdefgh12345678');
});

it('rejects an explicitly provided public id with the wrong prefix or shape', function (): void {
    $document = new Document(['title' => 'Bad']);
    $document->public_id = 'usr_abcdefgh12345678';

    expect(fn () => $document->save())->toThrow(InvalidPublicId::class);
});

it('is not mass assignable by default', function (): void {
    $document = Document::query()->create(['title' => 'Mass', 'public_id' => 'doc_abcdefgh12345678']);

    expect($document->public_id)->not->toBe('doc_abcdefgh12345678')
        ->and($document->public_id)->toStartWith('doc_');
});

it('is immutable once persisted', function (): void {
    $document = Document::query()->create(['title' => 'Fixed']);
    $document->public_id = 'doc_abcdefgh12345678';

    expect(fn () => $document->save())->toThrow(PublicIdImmutable::class);
});

it('exposes a global object id using the configured service name', function (): void {
    config()->set('addressing.service_name', 'example-service');
    $document = Document::query()->create(['title' => 'Global']);

    expect($document->globalObjectId()->toString())->toBe('example-service:document:'.$document->public_id);
});

it('finds models by public id and global object id and ignores foreign ones', function (): void {
    config()->set('addressing.service_name', 'svc');
    $document = Document::query()->create(['title' => 'Find me']);

    expect(Document::query()->wherePublicId($document->publicId())->exists())->toBeTrue()
        ->and(Document::query()->wherePublicId('doc_abcdefgh12345678')->exists())->toBeFalse()
        ->and(Document::query()->wherePublicId('garbage')->exists())->toBeFalse()
        ->and(Document::query()->whereGlobalObjectId($document->globalObjectId())->exists())->toBeTrue()
        ->and(Document::query()->whereGlobalObjectId('other:document:'.$document->public_id)->exists())->toBeFalse()
        ->and(Document::query()->whereGlobalObjectId('svc:tenant:'.$document->public_id)->exists())->toBeFalse();
});

it('binds routes by public id and returns 404 for malformed values', function (): void {
    $document = Document::query()->create(['title' => 'Routed']);

    Route::get('/documents/{document}', fn (Document $document) => $document->title)->middleware(SubstituteBindings::class);

    $this->get('/documents/'.$document->public_id)->assertOk()->assertSee('Routed');
    $this->get('/documents/doc_abcdefgh12345678')->assertNotFound();
    $this->get('/documents/'.$document->getKey())->assertNotFound();
});

it('retries on collisions and gives up after the configured attempts', function (): void {
    Document::query()->create(['title' => 'A', 'public_id' => 'doc_abcdefgh12345678']);
    Document::query()->create(['title' => 'B']);

    $generator = app(PublicIdGenerator::class);

    $unique = $generator->generateUnique('doc', 'documents', 'public_id', maxAttempts: 3);
    expect($unique->toString())->toStartWith('doc_');

    // A generator whose entropy source always yields the same bytes cannot succeed.
    $taken = new Document(['title' => 'C']);
    $taken->public_id = 'doc_'.Encoding::Hex->encode(str_repeat("\x42", 10));
    $taken->save();

    $colliding = new RandomPublicIdGenerator(
        connections: app(ConnectionResolverInterface::class),
        encoding: Encoding::Hex,
        entropySource: static fn (int $bytes): string => str_repeat("\x42", $bytes),
    );

    expect(fn () => $colliding->generateUnique('doc', 'documents', 'public_id', maxAttempts: 3))
        ->toThrow(PublicIdGenerationFailed::class);
});
