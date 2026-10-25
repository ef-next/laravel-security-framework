<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Verification;

use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Contracts\ChainVerifier;
use Efnext\Security\Audit\Contracts\Signer;
use Efnext\Security\Audit\Eloquent\AuditEvent;
use Efnext\Security\Audit\Enums\SignatureStatus;
use Efnext\Security\Audit\Enums\ViolationReason;
use Efnext\Security\Audit\Events\ChainViolationDetected;
use Efnext\Security\Audit\Hashing\EventHasher;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Audit\Records\VerificationReport;
use Efnext\Security\Audit\Records\Violation;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Walks a chain in sequence order, in chunks, and checks:
 *   - sequence continuity (gaps = deleted or injected rows)
 *   - previous_hash linkage
 *   - event_hash recomputation from the stored columns
 *   - HMAC signature (when signing is configured)
 *   - agreement with the chain head row (tail deletions)
 *   - agreement with the latest external anchor (wholesale rewrites)
 */
final class HashChainVerifier implements ChainVerifier
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly EventHasher $hasher,
        private readonly Signer $signer,
        private readonly AnchorStore $anchors,
        private readonly Container $container,
        private readonly ?string $connection = null,
        private readonly string $headsTable = 'audit_chain_heads',
        private readonly int $chunkSize = 1000,
    ) {}

    public function verify(ChainKey $chainKey, int $fromSequence = 1, ?int $toSequence = null): VerificationReport
    {
        $fromSequence = max(1, $fromSequence);
        $violations = [];
        $expectedPrevious = null;
        $expectedSequence = $fromSequence;
        $checked = 0;
        $lastHash = null;
        $lastSequence = null;
        $signatureStatus = $this->signer->isConfigured() ? SignatureStatus::Valid : SignatureStatus::NotConfigured;

        if ($fromSequence > 1) {
            $predecessor = $this->query($chainKey)->where('sequence', $fromSequence - 1)->first(['event_hash']);

            if ($predecessor === null) {
                $violations[] = new Violation(ViolationReason::MissingPredecessor, $fromSequence - 1);
            } else {
                $expectedPrevious = $predecessor->event_hash;
            }
        }

        $cursor = $fromSequence - 1;

        while (true) {
            $query = $this->query($chainKey)->where('sequence', '>', $cursor)->orderBy('sequence')->limit($this->chunkSize);

            if ($toSequence !== null) {
                $query->where('sequence', '<=', $toSequence);
            }

            $events = $query->get();

            if ($events->isEmpty()) {
                break;
            }

            foreach ($events as $event) {
                $checked++;

                if ($event->sequence !== $expectedSequence) {
                    $violations[] = new Violation(ViolationReason::SequenceGap, $event->sequence, $event->id, [
                        'expected_sequence' => $expectedSequence,
                    ]);
                }

                if ($event->previous_hash !== $expectedPrevious) {
                    $violations[] = new Violation(ViolationReason::PreviousHashMismatch, $event->sequence, $event->id, [
                        'expected_previous_hash' => $expectedPrevious,
                        'stored_previous_hash' => $event->previous_hash,
                    ]);
                }

                if (! $this->hasher->supports($event->hash_version)) {
                    $violations[] = new Violation(ViolationReason::UnsupportedHashVersion, $event->sequence, $event->id, [
                        'hash_version' => $event->hash_version,
                    ]);
                } else {
                    $recomputed = $this->hasher->hash($event->hashableRecord());

                    if (! hash_equals($recomputed, $event->event_hash)) {
                        $violations[] = new Violation(ViolationReason::EventHashMismatch, $event->sequence, $event->id, [
                            'expected_event_hash' => $recomputed,
                            'stored_event_hash' => $event->event_hash,
                        ]);
                    }
                }

                if ($this->signer->isConfigured()) {
                    $status = $event->signature === null
                        ? null
                        : $this->signer->verify($event->event_hash, $event->key_id, $event->signature);

                    if ($status === null) {
                        $violations[] = new Violation(ViolationReason::SignatureMissing, $event->sequence, $event->id);
                        $signatureStatus = SignatureStatus::Invalid;
                    } elseif ($status === SignatureStatus::UnknownKey) {
                        $violations[] = new Violation(ViolationReason::SignatureKeyUnknown, $event->sequence, $event->id, ['key_id' => $event->key_id]);
                        $signatureStatus = SignatureStatus::UnknownKey;
                    } elseif ($status !== SignatureStatus::Valid) {
                        $violations[] = new Violation(ViolationReason::SignatureInvalid, $event->sequence, $event->id, ['key_id' => $event->key_id]);
                        $signatureStatus = SignatureStatus::Invalid;
                    }
                }

                $expectedPrevious = $event->event_hash;
                $expectedSequence = $event->sequence + 1;
                $lastHash = $event->event_hash;
                $lastSequence = $event->sequence;
                $cursor = $event->sequence;
            }

            if ($events->count() < $this->chunkSize) {
                break;
            }
        }

        // Head and anchor comparisons only make sense when the walk reached the
        // tail (no upper bound) and actually covered something (or started at 1).
        $coveredSomething = $fromSequence === 1 || $lastSequence !== null;

        if ($toSequence === null && $coveredSomething) {
            array_push($violations, ...$this->checkHead($chainKey, $lastSequence, $lastHash));
        }

        $anchor = $this->anchors->latest($chainKey, $toSequence);

        if ($coveredSomething) {
            array_push($violations, ...$this->checkAnchor($chainKey, $anchor, $lastSequence));
        }

        $report = new VerificationReport(
            chainKey: $chainKey,
            fromSequence: $fromSequence,
            toSequence: $toSequence,
            eventsChecked: $checked,
            lastHash: $lastHash,
            violations: $violations,
            signatures: $signatureStatus,
            anchor: $anchor,
        );

        if (! $report->isIntact()) {
            $this->container->make(Dispatcher::class)->dispatch(new ChainViolationDetected($report));
        }

        return $report;
    }

    /**
     * @return list<ChainKey>
     */
    public function chains(): array
    {
        $keys = $this->connections->connection($this->connection)
            ->table($this->headsTable)
            ->orderBy('chain_key')
            ->pluck('chain_key');

        return array_values(array_map(static fn (string $key): ChainKey => ChainKey::fromString($key), $keys->all()));
    }

    /**
     * @return list<Violation>
     */
    private function checkHead(ChainKey $chainKey, ?int $lastSequence, ?string $lastHash): array
    {
        $head = $this->connections->connection($this->connection)
            ->table($this->headsTable)
            ->where('chain_key', $chainKey->toString())
            ->first(['last_sequence', 'last_hash']);

        if ($head === null) {
            return $lastSequence === null ? [] : [new Violation(ViolationReason::HeadMismatch, $lastSequence, null, ['reason' => 'head row missing'])];
        }

        $headSequence = (int) $head->last_sequence;
        $headHash = is_string($head->last_hash) && trim($head->last_hash) !== '' ? trim($head->last_hash) : null;

        if ($headSequence !== ($lastSequence ?? 0) || $headHash !== $lastHash) {
            return [new Violation(ViolationReason::HeadMismatch, $lastSequence, null, [
                'head_sequence' => $headSequence,
                'head_hash' => $headHash,
                'last_seen_sequence' => $lastSequence,
                'last_seen_hash' => $lastHash,
            ])];
        }

        return [];
    }

    /**
     * @return list<Violation>
     */
    private function checkAnchor(ChainKey $chainKey, ?Anchor $anchor, ?int $lastSequence): array
    {
        if ($anchor === null) {
            return [];
        }

        if ($lastSequence === null || $anchor->sequence > $lastSequence) {
            return [new Violation(ViolationReason::AnchorAhead, $anchor->sequence, null, [
                'anchored_at' => $anchor->anchoredAt->format(DATE_RFC3339_EXTENDED),
                'last_seen_sequence' => $lastSequence,
            ])];
        }

        $event = $this->query($chainKey)->where('sequence', $anchor->sequence)->first(['event_hash', 'id']);

        if ($event === null || ! hash_equals($anchor->eventHash, $event->event_hash)) {
            return [new Violation(ViolationReason::AnchorMismatch, $anchor->sequence, $event?->id, [
                'anchored_hash' => $anchor->eventHash,
                'stored_hash' => $event?->event_hash,
            ])];
        }

        if ($anchor->signature !== null && $this->signer->isConfigured()) {
            $status = $this->signer->verify($anchor->eventHash, $anchor->signature->keyId, $anchor->signature->value);

            if ($status !== SignatureStatus::Valid) {
                return [new Violation(ViolationReason::AnchorMismatch, $anchor->sequence, $event->id, ['reason' => 'anchor signature '.$status->value])];
            }
        }

        return [];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<AuditEvent>
     */
    private function query(ChainKey $chainKey): \Illuminate\Database\Eloquent\Builder
    {
        $model = new AuditEvent;

        if ($this->connection !== null) {
            $model->setConnection($this->connection);
        }

        return $model->newQuery()->forChain($chainKey);
    }
}
