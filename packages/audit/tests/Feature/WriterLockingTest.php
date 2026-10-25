<?php

declare(strict_types=1);

use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Driver-independent pins for the InnoDB deadlock fix: the chain head is
 * provisioned before the write transaction starts, FOR UPDATE only ever
 * targets an existing row, and concurrency errors are retried (with jitter)
 * only when the writer owns the transaction. The real multi-process check is
 * ConcurrencyTest (pgsql/mysql/mariadb).
 */

beforeEach(function (): void {
    Tenancy::clear();
});

function deadlockException(): QueryException
{
    return new QueryException('testing', 'select 1', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
}

/** The SELECT ... FOR UPDATE on the chain head (the lock clause is driver-specific and absent on SQLite). */
function isHeadLockSelect(string $sql): bool
{
    return (bool) preg_match('/^select ["`]?last_sequence["`]?, ["`]?last_hash["`]? from/i', $sql);
}

it('provisions the chain head outside the write transaction', function (): void {
    $timeline = [];

    Event::listen(TransactionBeginning::class, function () use (&$timeline): void {
        $timeline[] = 'BEGIN';
    });
    DB::listen(function (QueryExecuted $query) use (&$timeline): void {
        if (str_contains($query->sql, 'audit_chain_heads')) {
            $timeline[] = strtolower(strtok($query->sql, ' ') ?: '');
        }
    });

    Chains::write(Chains::TENANT_A, 2);

    // First event: exists-check, insert, BEGIN, locked select, update. Second event: exists-check, BEGIN, locked select, update.
    expect($timeline)->toBe(['select', 'insert', 'BEGIN', 'select', 'update', 'select', 'BEGIN', 'select', 'update'])
        ->and(DB::transactionLevel())->toBe(0);
});

it('creates chain heads on demand and idempotently', function (): void {
    Audit::prepareChain(Chains::TENANT_A);
    Audit::prepareChain(Chains::TENANT_A);
    Audit::prepareChain(null);

    // Ordered explicitly: without ORDER BY, InnoDB returns primary-key order, SQLite/PostgreSQL insertion order.
    expect(DB::table('audit_chain_heads')->orderBy('chain_key')->pluck('last_sequence', 'chain_key')->all())
        ->toBe(['global' => 0, 'tenant:'.Chains::TENANT_A => 0]);

    $inserts = 0;
    DB::listen(function (QueryExecuted $query) use (&$inserts): void {
        if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'audit_chain_heads')) {
            $inserts++;
        }
    });

    [$event] = Chains::write(Chains::TENANT_A, 1);

    expect($event->sequence)->toBe(1)->and($inserts)->toBe(0);
});

it('retries a deadlocked write when it owns the transaction', function (): void {
    $lockedSelects = 0;
    DB::listen(function (QueryExecuted $query) use (&$lockedSelects): void {
        if (isHeadLockSelect($query->sql)) {
            $lockedSelects++;

            if ($lockedSelects <= 2) {
                throw deadlockException();
            }
        }
    });

    [$event] = Chains::write(Chains::TENANT_A, 1);

    expect($event->sequence)->toBe(1)
        ->and($lockedSelects)->toBe(3)
        ->and(DB::table('audit_events')->count())->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);

    expect(Audit::verifyTenant(Chains::TENANT_A)->isIntact())->toBeTrue();
});

it('gives up after the configured number of attempts', function (): void {
    config()->set('audit.write.attempts', 2);
    app()->forgetInstance(Efnext\Security\Audit\Contracts\AuditWriter::class);
    app()->forgetInstance(Efnext\Security\Audit\AuditManager::class);
    Audit::clearResolvedInstance(Efnext\Security\Audit\AuditManager::class);

    $lockedSelects = 0;
    DB::listen(function (QueryExecuted $query) use (&$lockedSelects): void {
        if (isHeadLockSelect($query->sql)) {
            $lockedSelects++;
            throw deadlockException();
        }
    });

    expect(fn () => Chains::write(Chains::TENANT_A, 1))->toThrow(QueryException::class)
        ->and($lockedSelects)->toBe(2)
        ->and(DB::table('audit_events')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('does not retry inside a caller transaction but hands the error to it', function (): void {
    $lockedSelects = 0;
    DB::listen(function (QueryExecuted $query) use (&$lockedSelects): void {
        if (isHeadLockSelect($query->sql)) {
            $lockedSelects++;
            throw deadlockException();
        }
    });

    $attempts = 0;

    expect(function () use (&$attempts): void {
        DB::transaction(function () use (&$attempts): void {
            $attempts++;
            DB::table('audit_anchors')->count(); // some business work in the same transaction
            Chains::write(Chains::TENANT_A, 1);
        });
    })->toThrow(DeadlockException::class)
        ->and($lockedSelects)->toBe(1)
        ->and($attempts)->toBe(1)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::table('audit_events')->count())->toBe(0);
});

it('retries a whole business transaction with Audit::transaction()', function (): void {
    $lockedSelects = 0;
    DB::listen(function (QueryExecuted $query) use (&$lockedSelects): void {
        if (isHeadLockSelect($query->sql)) {
            $lockedSelects++;

            if ($lockedSelects <= 2) {
                throw deadlockException();
            }
        }
    });

    $attempts = 0;
    $result = Audit::transaction(function () use (&$attempts): int {
        $attempts++;
        DB::table('audit_anchors')->count();
        Chains::write(Chains::TENANT_A, 1);

        return $attempts;
    });

    expect($result)->toBe(3)
        ->and($lockedSelects)->toBe(3)
        ->and(DB::table('audit_events')->count())->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);

    expect(Audit::verifyTenant(Chains::TENANT_A)->isIntact())->toBeTrue();
});

it('gives up in Audit::transaction() after the given attempts and never retries other errors', function (): void {
    $calls = 0;
    $alwaysDeadlocks = function () use (&$calls): void {
        $calls++;
        throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
    };

    expect(function () use ($alwaysDeadlocks): void {
        Audit::transaction($alwaysDeadlocks, attempts: 3);
    })->toThrow(PDOException::class)
        ->and($calls)->toBe(3)
        ->and(DB::transactionLevel())->toBe(0);

    $calls = 0;
    $businessError = function () use (&$calls): void {
        $calls++;
        throw new RuntimeException('business rule violated');
    };

    expect(function () use ($businessError): void {
        Audit::transaction($businessError);
    })->toThrow(RuntimeException::class)
        ->and($calls)->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);
});

it('runs Audit::transaction() once when a transaction is already open', function (): void {
    $calls = 0;
    $alwaysDeadlocks = function () use (&$calls): void {
        $calls++;
        throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
    };

    expect(function () use ($alwaysDeadlocks): void {
        DB::transaction(function () use ($alwaysDeadlocks): void {
            Audit::transaction($alwaysDeadlocks);
        });
    })->toThrow(PDOException::class)
        ->and($calls)->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);
});

it('does not retry non-concurrency errors', function (): void {
    $lockedSelects = 0;
    DB::listen(function (QueryExecuted $query) use (&$lockedSelects): void {
        if (isHeadLockSelect($query->sql)) {
            $lockedSelects++;
            throw new QueryException('testing', $query->sql, [], new PDOException('SQLSTATE[42S02]: Base table or view not found'));
        }
    });

    expect(fn () => Chains::write(Chains::TENANT_A, 1))->toThrow(QueryException::class)
        ->and($lockedSelects)->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);
});
