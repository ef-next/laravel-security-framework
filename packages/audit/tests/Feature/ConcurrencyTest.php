<?php

declare(strict_types=1);

use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Real parallel writers against a real database: several forked processes
 * append to the same chain at the same time. Without the head lock this
 * produces duplicate sequences or forked previous_hash links.
 */
/**
 * Fork $workers processes that each run $work(workerIndex) against a fresh
 * connection; returns the number of workers that threw.
 */
function runForkedWriters(int $workers, Closure $work): int
{
    DB::disconnect();
    $pids = [];

    for ($w = 0; $w < $workers; $w++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Could not fork.');
        }

        if ($pid === 0) {
            // Child: fresh connection, write, then vanish without running PHPUnit's shutdown.
            DB::purge();
            $failed = false;

            try {
                $work($w);
            } catch (Throwable $e) {
                fwrite(STDERR, sprintf("worker %d failed: %s\n", $w, $e->getMessage()));
                $failed = true;
            }

            DB::disconnect();
            posix_kill(posix_getpid(), $failed ? SIGUSR1 : SIGKILL);
        }

        $pids[] = $pid;
    }

    $childFailures = 0;

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);

        if (pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGUSR1) {
            $childFailures++;
        }
    }

    DB::purge();

    return $childFailures;
}

function expectLinearChain(int $total): void
{
    $sequences = DB::table('audit_events')->orderBy('sequence')->pluck('sequence')->all();

    expect(count($sequences))->toBe($total)
        ->and($sequences)->toBe(range(1, $total))
        ->and((int) DB::table('audit_chain_heads')->value('last_sequence'))->toBe($total);

    $report = Audit::verifyTenant(Chains::TENANT_A);

    expect($report->isIntact())->toBeTrue()->and($report->eventsChecked)->toBe($total);
}

beforeEach(function (): void {
    $this->skipUnlessDriver('pgsql', 'mysql', 'mariadb');

    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl/posix are required for the concurrency test.');
    }
});

it('keeps one linear chain under concurrent writers, including the first event of the chain', function (): void {
    $workers = 6;
    $perWorker = 15;

    $failures = runForkedWriters($workers, function (int $w) use ($perWorker): void {
        Tenancy::run(Chains::TENANT_A, function () use ($perWorker, $w): void {
            for ($i = 0; $i < $perWorker; $i++) {
                Audit::action('load.test')->on('worker', 'w'.$w)->with(['i' => $i])->record();
            }
        });
    });

    expect($failures)->toBe(0);
    expectLinearChain($workers * $perWorker);
})->group('concurrency');

it('keeps one linear chain when writers append inside their own transactions', function (): void {
    $workers = 6;
    $perWorker = 10;

    // Business transactions that contain audit writes. The head row exists up
    // front (prepareChain), so FOR UPDATE only ever queues on a record lock,
    // and Audit::transaction() retries the whole business transaction with
    // back-off on the conflicts every database can still raise here (InnoDB
    // deadlocks, PostgreSQL serialization failures, MariaDB >= 11.6
    // "Record has changed since last read" under snapshot isolation).
    Audit::prepareChain(Chains::TENANT_A);

    $failures = runForkedWriters($workers, function (int $w) use ($perWorker): void {
        Tenancy::run(Chains::TENANT_A, function () use ($perWorker, $w): void {
            for ($i = 0; $i < $perWorker; $i++) {
                Audit::transaction(function () use ($w, $i): void {
                    DB::table('audit_anchors')->count(); // unrelated work first: establishes the snapshot
                    Audit::action('load.tx')->on('worker', 'w'.$w)->with(['i' => $i])->record();
                });
            }
        });
    });

    expect($failures)->toBe(0);
    expectLinearChain($workers * $perWorker);
})->group('concurrency');

/*
 * Opt-in load test: many more writers than CPU cores, mixed chains, mixed
 * transaction styles. Not part of the default run; enable with
 *   AUDIT_LOAD_WRITERS=48 AUDIT_LOAD_EVENTS=25 ./start-security-framework.sh test mysql --group=load
 */
it('stays linear under heavy contention from many writers on several chains', function (): void {
    $workers = (int) (getenv('AUDIT_LOAD_WRITERS') ?: 0);
    $perWorker = (int) (getenv('AUDIT_LOAD_EVENTS') ?: 20);

    if ($workers < 2) {
        $this->markTestSkipped('Set AUDIT_LOAD_WRITERS (e.g. 48) to run the load test.');
    }

    Chains::tenant(Chains::TENANT_B, 'beta');

    $started = microtime(true);
    $failures = runForkedWriters($workers, function (int $w) use ($perWorker): void {
        // Half of the workers hammer chain A, the other half chain B; every third
        // worker writes inside business transactions.
        $tenant = $w % 2 === 0 ? Chains::TENANT_A : Chains::TENANT_B;

        Tenancy::run($tenant, function () use ($perWorker, $w): void {
            for ($i = 0; $i < $perWorker; $i++) {
                if ($w % 3 === 0) {
                    Audit::transaction(function () use ($w, $i): void {
                        DB::table('audit_anchors')->count();
                        Audit::action('load.heavy')->on('worker', 'w'.$w)->with(['i' => $i])->record();
                    }, attempts: 25);
                } else {
                    Audit::action('load.heavy')->on('worker', 'w'.$w)->with(['i' => $i])->record();
                }
            }
        });
    });
    $seconds = microtime(true) - $started;

    expect($failures)->toBe(0);

    foreach ([Chains::TENANT_A, Chains::TENANT_B] as $tenant) {
        $expected = intdiv($workers, 2) * $perWorker + ($tenant === Chains::TENANT_A ? ($workers % 2) * $perWorker : 0);
        $sequences = DB::table('audit_events')->where('tenant_id', $tenant)->orderBy('sequence')->pluck('sequence')->all();

        expect($sequences)->toBe(range(1, $expected))
            ->and(Audit::verifyTenant($tenant)->isIntact())->toBeTrue();
    }

    fwrite(STDERR, sprintf("\nload: %d workers x %d events = %d events in %.1fs (%.0f events/s)\n", $workers, $perWorker, $workers * $perWorker, $seconds, $workers * $perWorker / max(0.001, $seconds)));
})->group('concurrency', 'load');
