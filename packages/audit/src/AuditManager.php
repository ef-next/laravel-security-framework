<?php

declare(strict_types=1);

namespace Efnext\Security\Audit;

use Closure;
use Efnext\Security\Audit\Contracts\AuditWriter;
use Efnext\Security\Audit\Contracts\ChainVerifier;
use Efnext\Security\Audit\Eloquent\AuditEvent;
use Efnext\Security\Audit\Records\AuditEntry;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Audit\Records\VerificationReport;
use Efnext\Security\Tenant\Context\TenantId;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DetectsConcurrencyErrors;
use Throwable;

/**
 * Facade target. Thin: builds entries and delegates to the writer/verifier.
 */
final class AuditManager
{
    use DetectsConcurrencyErrors;

    public function __construct(
        private readonly AuditWriter $writer,
        private readonly ChainVerifier $verifier,
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection = null,
    ) {}

    /**
     * Run a business transaction that contains audit writes, retrying the whole
     * transaction on concurrency errors with jittered back-off.
     *
     * Why: an audit write inside your transaction locks the tenant's chain head
     * at the point of the write. Under contention that is a retryable conflict
     * on every database (InnoDB deadlock on a brand-new chain, PostgreSQL
     * serialization failure under REPEATABLE READ, MariaDB >= 11.6
     * "Record has changed since last read" thanks to snapshot isolation).
     * DB::transaction($fn, attempts: n) retries immediately and tends to
     * collide again; this backs off. Inside an already open transaction the
     * callback runs once and errors propagate to the outer transaction.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function transaction(Closure $callback, int $attempts = 10): mixed
    {
        $connection = $this->connections->connection($this->connection);
        $attempts = $connection->transactionLevel() === 0 ? max(1, $attempts) : 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction($callback);
            } catch (Throwable $e) {
                if ($attempt >= $attempts || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                usleep(random_int(2_000, 20_000) * $attempt);
            }
        }
    }

    public function action(string $action): AuditBuilder
    {
        return new AuditBuilder($this->writer, $action);
    }

    public function record(AuditEntry $entry): AuditEvent
    {
        return $this->writer->record($entry);
    }

    /**
     * Create a tenant's chain head ahead of its first event (null = global chain).
     */
    public function prepareChain(TenantId|string|null $tenantId): void
    {
        $this->writer->prepareChain(is_string($tenantId) ? TenantId::fromString($tenantId) : $tenantId);
    }

    public function verifyTenant(TenantId|string $tenantId, int $fromSequence = 1, ?int $toSequence = null): VerificationReport
    {
        $id = $tenantId instanceof TenantId ? $tenantId : TenantId::fromString($tenantId);

        return $this->verifier->verify(ChainKey::forTenant($id), $fromSequence, $toSequence);
    }

    public function verifyGlobal(int $fromSequence = 1, ?int $toSequence = null): VerificationReport
    {
        return $this->verifier->verify(ChainKey::global(), $fromSequence, $toSequence);
    }

    public function verify(ChainKey $chainKey, int $fromSequence = 1, ?int $toSequence = null): VerificationReport
    {
        return $this->verifier->verify($chainKey, $fromSequence, $toSequence);
    }
}
