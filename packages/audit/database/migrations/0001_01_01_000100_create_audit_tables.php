<?php

declare(strict_types=1);

use Efnext\Security\Audit\Database\AppendOnly;
use Efnext\Security\Audit\Exceptions\UnsupportedDatabaseDriver;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('audit.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnection();
    }

    public function up(): void
    {
        $events = (string) config('audit.table', 'audit_events');
        $heads = (string) config('audit.heads_table', 'audit_chain_heads');
        $anchors = (string) config('audit.anchors_table', 'audit_anchors');

        Schema::create($heads, function (Blueprint $table): void {
            $table->string('chain_key', 191)->primary();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->char('last_hash', 64)->nullable();
            $table->timestampTz('updated_at', 6)->nullable();
        });

        Schema::create($events, function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('chain_key', 191);
            $table->string('tenant_id', 191)->nullable();
            $table->unsignedBigInteger('sequence');
            $table->char('previous_hash', 64)->nullable();
            $table->char('event_hash', 64);
            $table->unsignedSmallInteger('hash_version');
            $table->string('key_id', 32)->nullable();
            $table->char('signature', 64)->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->unsignedBigInteger('occurred_at_us');
            $table->timestampTz('recorded_at', 6);
            $table->string('actor_type', 16);
            $table->string('actor_id', 191)->nullable();
            $table->string('actor_label', 191)->nullable();
            $table->string('action', 191);
            $table->string('resource_type', 64);
            $table->string('resource_id', 191);
            $table->string('outcome', 16);
            $table->json('metadata')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->unique(['chain_key', 'sequence']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('action');
            $table->index('request_id');
            $table->index('correlation_id');
        });

        Schema::create($anchors, function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('chain_key', 191);
            $table->unsignedBigInteger('sequence');
            $table->char('event_hash', 64);
            $table->string('key_id', 32)->nullable();
            $table->char('signature', 64)->nullable();
            $table->timestampTz('anchored_at', 6);
            $table->timestampTz('created_at', 6);

            $table->index(['chain_key', 'sequence']);
        });

        // Bookkeeping for audit:verify / security:doctor: when was which chain
        // last verified, and with what result. Operational data, not evidence:
        // the chain, signatures and anchors remain the proof.
        Schema::create((string) config('audit.verification_runs_table', 'audit_verification_runs'), function (Blueprint $table): void {
            $table->id();
            $table->string('chain_key', 191);
            $table->string('source', 32);
            $table->unsignedBigInteger('from_sequence');
            $table->unsignedBigInteger('to_sequence')->nullable();
            $table->unsignedBigInteger('events_checked');
            $table->unsignedInteger('violations');
            $table->boolean('intact');
            $table->timestampTz('started_at', 6);
            $table->timestampTz('finished_at', 6);

            $table->index(['chain_key', 'finished_at']);
        });

        $this->installAppendOnlyGuards([$events, $anchors]);
    }

    public function down(): void
    {
        $events = (string) config('audit.table', 'audit_events');
        $anchors = (string) config('audit.anchors_table', 'audit_anchors');

        $this->dropAppendOnlyGuards([$events, $anchors]);

        Schema::dropIfExists((string) config('audit.verification_runs_table', 'audit_verification_runs'));
        Schema::dropIfExists($anchors);
        Schema::dropIfExists($events);
        Schema::dropIfExists((string) config('audit.heads_table', 'audit_chain_heads'));
    }

    /**
     * @param  list<string>  $tables
     */
    private function installAppendOnlyGuards(array $tables): void
    {
        $connection = DB::connection($this->getConnection());
        $driver = $connection->getDriverName();

        if (! filter_var(config('audit.enforce_append_only', true), FILTER_VALIDATE_BOOL)) {
            Log::warning('audit: append-only enforcement is DISABLED (audit.enforce_append_only=false). Audit rows can be updated and deleted at the database level.');

            return;
        }

        foreach ($tables as $table) {
            match ($driver) {
                'pgsql' => $this->pgsqlGuards($connection, $table),
                'mysql', 'mariadb' => $this->mysqlGuards($connection, $table),
                'sqlite' => $this->sqliteGuards($connection, $table),
                default => throw UnsupportedDatabaseDriver::forAppendOnly($driver),
            };
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function dropAppendOnlyGuards(array $tables): void
    {
        $connection = DB::connection($this->getConnection());
        $driver = $connection->getDriverName();

        foreach ($tables as $table) {
            $wrapped = $connection->getQueryGrammar()->wrapTable($table);

            foreach (['update', 'delete'] as $op) {
                $trigger = $this->triggerName($table, $op);

                match ($driver) {
                    'pgsql' => $connection->unprepared(sprintf('DROP TRIGGER IF EXISTS %s ON %s', $trigger, $wrapped)),
                    'mysql', 'mariadb', 'sqlite' => $connection->unprepared(sprintf('DROP TRIGGER IF EXISTS %s', $trigger)),
                    default => null,
                };
            }
        }

        if ($driver === 'pgsql') {
            $connection->unprepared('DROP FUNCTION IF EXISTS efnext_audit_prevent_mutation()');
        }
    }

    private function pgsqlGuards(Connection $connection, string $table): void
    {
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);

        $connection->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION efnext_audit_prevent_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit records are append-only (%.%)', TG_TABLE_SCHEMA, TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;
            SQL);

        $connection->unprepared(sprintf(
            'CREATE TRIGGER %s BEFORE UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION efnext_audit_prevent_mutation()',
            $this->triggerName($table, 'update'),
            $wrapped
        ));

        $connection->unprepared(sprintf(
            'CREATE TRIGGER %s BEFORE DELETE ON %s FOR EACH ROW EXECUTE FUNCTION efnext_audit_prevent_mutation()',
            $this->triggerName($table, 'delete'),
            $wrapped
        ));
    }

    private function mysqlGuards(Connection $connection, string $table): void
    {
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);

        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $op => $statement) {
            $connection->unprepared(sprintf(
                "CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit records are append-only'",
                $this->triggerName($table, $op),
                $statement,
                $wrapped
            ));
        }
    }

    private function sqliteGuards(Connection $connection, string $table): void
    {
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);

        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $op => $statement) {
            $connection->unprepared(sprintf(
                "CREATE TRIGGER %s BEFORE %s ON %s BEGIN SELECT RAISE(ABORT, 'audit records are append-only'); END",
                $this->triggerName($table, $op),
                $statement,
                $wrapped
            ));
        }
    }

    private function triggerName(string $table, string $operation): string
    {
        return AppendOnly::triggerName($table, $operation);
    }
};
