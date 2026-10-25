<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Doctor;

use DateTimeImmutable;
use DateTimeZone;
use Efnext\Security\Audit\Anchoring\CompositeAnchorStore;
use Efnext\Security\Audit\Anchoring\DatabaseAnchorStore;
use Efnext\Security\Audit\Anchoring\LogAnchorStore;
use Efnext\Security\Audit\Anchoring\NullAnchorStore;
use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Contracts\ChainVerifier;
use Efnext\Security\Audit\Database\AppendOnly;
use Efnext\Security\Audit\Exceptions\InvalidSigningKey;
use Efnext\Security\Audit\Exceptions\UnsupportedDatabaseDriver;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Audit\Verification\VerificationRuns;
use Efnext\Security\Tenant\Http\Middleware\ResolveTenant;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use PDO;
use Throwable;

/**
 * Production-readiness checks for the security framework: configuration,
 * database protection, anchoring and verification hygiene, tenant wiring.
 *
 * Every check is independent and reports OK, WARN, FAIL or SKIP; a failing
 * check never aborts the others.
 */
final class SecurityDoctor
{
    private bool $strict = false;

    public function __construct(
        private readonly Container $container,
        private readonly ConfigRepository $config,
        private readonly ConnectionResolverInterface $connections,
        private readonly ProductionGuard $guard,
        private readonly VerificationRuns $runs,
        private readonly ?string $connection = null,
    ) {}

    /**
     * @param  bool  $strict  the high-security profile: warnings count as failures (command level) and
     *                        the external-anchor requirement must be *enforced* at boot, not only met here
     */
    public function run(bool $verifyTails = true, bool $strict = false): DoctorReport
    {
        $this->strict = $strict;

        $checks = [
            $this->checkEnvironment(),
            $this->checkAppKey(),
            $this->checkSigningKey(),
            $this->checkDriver(),
            $this->checkAppendOnlyConfig(),
            $this->checkTriggers(),
            $this->checkAnchorStores(),
            $this->checkSeparateConnection(),
            $this->checkAnchorFreshness(),
            $this->checkVerificationRecency(),
            $verifyTails ? $this->checkTails() : Check::skip('tail_verification', 'Tail verification', 'Skipped (--no-verify).'),
            $this->checkMiddlewarePriority(),
            $this->checkTenantCache(),
        ];

        return new DoctorReport($checks);
    }

    private function checkEnvironment(): Check
    {
        $environment = $this->environment();
        $debug = filter_var($this->config->get('app.debug', false), FILTER_VALIDATE_BOOL);
        $failFast = filter_var($this->config->get('audit.production.fail_fast', true), FILTER_VALIDATE_BOOL);

        if ($environment === 'production' && $debug) {
            return Check::warn('environment', 'Environment', 'APP_ENV=production with APP_DEBUG=true.', 'Debug pages leak configuration and stack traces; set APP_DEBUG=false.');
        }

        if ($environment === 'production' && ! $failFast) {
            return Check::warn('environment', 'Environment', 'APP_ENV=production with AUDIT_FAIL_FAST=false.', 'The boot-time guard is disabled; re-enable it once the configuration is fixed.');
        }

        return Check::ok('environment', 'Environment', sprintf('APP_ENV=%s, debug %s, fail-fast %s.', $environment, $debug ? 'on' : 'off', $failFast ? 'on' : 'off'));
    }

    private function checkAppKey(): Check
    {
        $appKey = $this->config->get('app.key');

        if (! is_string($appKey) || $appKey === '') {
            return Check::fail('app_key', 'Application key', 'APP_KEY is empty.', 'Pseudonymised identifiers in audit metadata derive their pepper from APP_KEY; without it they are replaced by the redaction marker.');
        }

        return Check::ok('app_key', 'Application key', 'APP_KEY is set (pseudonymisation pepper derived from it).');
    }

    private function checkSigningKey(): Check
    {
        try {
            $keyring = $this->guard->keyring();
        } catch (InvalidSigningKey $e) {
            return Check::fail('signing_key', 'Signing key', 'Invalid: '.$e->getMessage(), 'Generate one with `php artisan audit:key:generate` and set AUDIT_SIGNING_KEYS / AUDIT_SIGNING_ACTIVE_KEY.');
        }

        if ($keyring->isEmpty()) {
            return Check::fail('signing_key', 'Signing key', 'No signing key configured; events are hashed but not signed.', 'Without a key an attacker with database access can rewrite a chain consistently. Run `php artisan audit:key:generate`.');
        }

        return Check::ok('signing_key', 'Signing key', sprintf('%d key(s) in the ring, active: %s.', count($keyring->ids()), (string) $keyring->activeKeyId()));
    }

    private function checkDriver(): Check
    {
        $driver = $this->guard->auditDriver();
        $name = $this->guard->auditConnectionName();

        if ($driver === 'sqlite') {
            return Check::fail('database_driver', 'Database driver', sprintf('Connection "%s" uses SQLite.', $name), 'Fine for development and tests; production needs PostgreSQL, MySQL or MariaDB (row locks across connections, durability).');
        }

        if (! in_array($driver, ProductionGuard::SUPPORTED_DRIVERS, true)) {
            return Check::fail('database_driver', 'Database driver', sprintf('Connection "%s" uses unsupported driver "%s".', $name, $driver), 'Append-only triggers exist for PostgreSQL, MySQL and MariaDB only.');
        }

        try {
            $connection = $this->connection();
            $version = $connection instanceof Connection ? $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION) : null;
        } catch (Throwable $e) {
            return Check::fail('database_driver', 'Database driver', sprintf('Connection "%s" (%s) is not reachable: %s', $name, $driver, $e->getMessage()));
        }

        return Check::ok('database_driver', 'Database driver', sprintf('Connection "%s": %s %s.', $name, $driver, is_string($version) ? $version : ''));
    }

    private function checkAppendOnlyConfig(): Check
    {
        $enabled = filter_var($this->config->get('audit.enforce_append_only', true), FILTER_VALIDATE_BOOL);

        return $enabled
            ? Check::ok('append_only_config', 'Append-only enforcement', 'Enabled (triggers are installed by the migration).')
            : Check::fail('append_only_config', 'Append-only enforcement', 'Disabled via AUDIT_ENFORCE_APPEND_ONLY=false.', 'Audit rows can be updated and deleted at the database level; enable it and re-run the migration.');
    }

    private function checkTriggers(): Check
    {
        $tables = [$this->table('table', 'audit_events'), $this->table('anchors_table', 'audit_anchors')];
        $missing = [];

        try {
            $connection = $this->connection();

            foreach ($tables as $table) {
                foreach (AppendOnly::missingTriggers($connection, $table) as $trigger) {
                    $missing[] = $trigger;
                }
            }
        } catch (UnsupportedDatabaseDriver $e) {
            return Check::fail('append_only_triggers', 'Append-only triggers', $e->getMessage());
        } catch (Throwable $e) {
            return Check::fail('append_only_triggers', 'Append-only triggers', 'Could not inspect triggers: '.$e->getMessage(), 'Has the audit migration run on this connection?');
        }

        if ($missing !== []) {
            return Check::fail('append_only_triggers', 'Append-only triggers', 'Missing: '.implode(', ', $missing).'.', 'Someone dropped them or the migration ran with enforcement disabled. Re-run the audit migration (down/up) or recreate the triggers; then run `audit:verify` — rows may have been altered meanwhile.');
        }

        return Check::ok('append_only_triggers', 'Append-only triggers', sprintf('All %d triggers present on %s.', count($tables) * count(AppendOnly::OPERATIONS), implode(' and ', $tables)));
    }

    private function checkAnchorStores(): Check
    {
        $configured = $this->guard->configuredAnchorStores();
        $readable = $this->guard->readableAnchorStores();

        if ($readable === []) {
            return Check::fail('anchor_stores', 'Anchor stores', $configured === [] ? 'None configured.' : 'Only write-only stores configured ('.implode(', ', $configured).').', 'The verifier needs a store it can read back. Configure `filesystem` (object storage with object lock) first and keep `database` as fallback.');
        }

        try {
            $store = $this->container->make(AnchorStore::class);
            $store->latest(ChainKey::global());
        } catch (Throwable $e) {
            return Check::fail('anchor_stores', 'Anchor stores', 'Configured ('.implode(', ', $configured).') but not readable: '.$e->getMessage());
        }

        $external = $this->guard->externalAnchorStores();

        if ($this->guard->requiresExternalAnchor()) {
            $problems = $this->guard->externalAnchorProblems();

            if ($problems !== []) {
                return Check::fail('anchor_stores', 'Anchor stores', implode(' ', $problems), 'AUDIT_REQUIRE_EXTERNAL_ANCHOR is on: production will not boot like this. Configure `filesystem` on object storage with object lock (or a custom external store), listed first.');
            }

            // Configuration passed; now the external stores themselves must exist and answer.
            $instances = $this->externalStoreInstances($store);

            if ($instances === []) {
                return Check::fail('anchor_stores', 'Anchor stores', 'The configured external anchor store did not materialise as a store outside the database.', 'A custom store must not extend the database, log or null store. Check audit.anchoring.stores.');
            }

            foreach ($instances as $instance) {
                try {
                    $instance->latest(ChainKey::global());
                } catch (Throwable $e) {
                    return Check::fail('anchor_stores', 'Anchor stores', sprintf('External store "%s" is not readable: %s', $instance->name(), $e->getMessage()));
                }
            }
        }

        if ($this->strict && ! $this->guard->requiresExternalAnchor()) {
            // Strict is the deployment gate of the high-security profile: the external anchor must be
            // enforced by the boot guard, not merely present today. A store that is configured but not
            // required can be dropped from the configuration later without anything refusing to start.
            return Check::fail('anchor_stores', 'Anchor stores', sprintf('Strict mode: AUDIT_REQUIRE_EXTERNAL_ANCHOR is off (external stores configured: %s).', $external === [] ? 'none' : implode(', ', $external)), 'The strict profile expects production to refuse to boot without an anchor store outside the database. Set AUDIT_REQUIRE_EXTERNAL_ANCHOR=true and configure `filesystem` on object storage with object lock (or a custom external store), listed first.');
        }

        if ($external === []) {
            return Check::warn('anchor_stores', 'Anchor stores', 'Only the database store is readable.', 'Anchors inside the audit database are not independent of it; an attacker who can rewrite the chain can rewrite the anchors. Add `filesystem` (S3 with Object Lock) or a custom external store, listed first, and consider AUDIT_REQUIRE_EXTERNAL_ANCHOR=true.');
        }

        return Check::ok('anchor_stores', 'Anchor stores', sprintf('Configured: %s (readable: %s; external: %s%s).', implode(', ', $configured), implode(', ', $readable), implode(', ', $external), $this->guard->requiresExternalAnchor() ? ', required' : ''));
    }

    private function checkSeparateConnection(): Check
    {
        $default = (string) $this->config->get('database.default', '');
        $audit = $this->guard->auditConnectionName();

        if ($audit !== $default) {
            return Check::warn('separate_connection', 'Audit connection', sprintf('Audit tables use connection "%s", the application uses "%s".', $audit, $default), 'A separate connection cannot share a transaction with business writes: a crash between the two leaves one without the other. Good for privilege separation; make sure no code relies on `Audit::transaction()` for cross-connection atomicity.');
        }

        return Check::ok('separate_connection', 'Audit connection', sprintf('Audit tables share the application connection "%s"; `Audit::transaction()` is atomic with business writes.', $audit));
    }

    private function checkAnchorFreshness(): Check
    {
        $maxAge = max(1, (int) $this->config->get('audit.doctor.max_anchor_age_minutes', 60));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $heads = $this->heads();
            $store = $this->container->make(AnchorStore::class);
        } catch (Throwable $e) {
            return Check::fail('anchor_freshness', 'Anchor freshness', 'Could not read chain heads or anchors: '.$e->getMessage());
        }

        if ($heads === []) {
            return Check::skip('anchor_freshness', 'Anchor freshness', 'No chains yet.');
        }

        // With the strict profile an anchor only counts when a store outside the database holds it:
        // a composite store would otherwise answer from the database while the external store stays empty.
        $external = $this->guard->requiresExternalAnchor() ? $this->externalStoreInstances($store) : [];

        if ($this->guard->requiresExternalAnchor() && $external === []) {
            return Check::fail('anchor_freshness', 'Anchor freshness', 'AUDIT_REQUIRE_EXTERNAL_ANCHOR is on but no store outside the database is available to read anchors from.');
        }

        $anchored = 0;
        $pending = 0;
        $stale = [];

        foreach ($heads as $head) {
            $anchor = $external === [] ? $store->latest($head['chain']) : $this->latestExternal($external, $head['chain']);

            if ($anchor !== null && $anchor->sequence >= $head['last_sequence']) {
                $anchored++;

                continue;
            }

            $ageMinutes = $head['updated_at'] === null ? PHP_INT_MAX : (int) floor(($now->getTimestamp() - $head['updated_at']->getTimestamp()) / 60);

            if ($ageMinutes > $maxAge) {
                $stale[] = sprintf('%s (#%d unanchored for %s)', $head['chain']->toString(), $head['last_sequence'], $ageMinutes === PHP_INT_MAX ? 'ever' : $ageMinutes.' min');
            } else {
                $pending++;
            }
        }

        $where = $external === [] ? '' : sprintf(' in the external store(s) %s', implode(', ', array_map(static fn (AnchorStore $s): string => $s->name(), $external)));

        if ($stale !== []) {
            return Check::fail('anchor_freshness', 'Anchor freshness', sprintf('%d chain(s) with events older than %d min that are not anchored%s: %s.', count($stale), $maxAge, $where, implode('; ', array_slice($stale, 0, 5)).(count($stale) > 5 ? ', …' : '')), 'Schedule `php artisan audit:anchor` (every few minutes, onOneServer) and check that it runs'.($external === [] ? '.' : ' and that the external store accepts writes.'));
        }

        return Check::ok('anchor_freshness', 'Anchor freshness', sprintf('%d chain(s) anchored at their head%s, %d with recent unanchored events (within %d min).', $anchored, $where, $pending, $maxAge));
    }

    /**
     * The configured stores that live outside the audit database, as instances
     * (a composite store is unpacked). Database, log and null stores never qualify.
     *
     * @return list<AnchorStore>
     */
    private function externalStoreInstances(AnchorStore $store): array
    {
        $stores = $store instanceof CompositeAnchorStore ? $store->stores() : [$store];

        return array_values(array_filter(
            $stores,
            static fn (AnchorStore $s): bool => ! $s instanceof DatabaseAnchorStore && ! $s instanceof LogAnchorStore && ! $s instanceof NullAnchorStore && ! $s instanceof CompositeAnchorStore,
        ));
    }

    /**
     * The most advanced anchor any of the given stores holds for a chain.
     *
     * @param  list<AnchorStore>  $stores
     */
    private function latestExternal(array $stores, ChainKey $chain): ?Anchor
    {
        $best = null;

        foreach ($stores as $store) {
            $anchor = $store->latest($chain);

            if ($anchor !== null && ($best === null || $anchor->sequence > $best->sequence)) {
                $best = $anchor;
            }
        }

        return $best;
    }

    private function checkVerificationRecency(): Check
    {
        $maxAgeHours = max(1, (int) $this->config->get('audit.doctor.max_verification_age_hours', 24));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $heads = $this->heads();
        } catch (Throwable $e) {
            return Check::fail('verification_recency', 'Full verification', 'Could not read chain heads: '.$e->getMessage());
        }

        if ($heads === []) {
            return Check::skip('verification_recency', 'Full verification', 'No chains yet.');
        }

        $recent = 0;
        $missing = [];
        $broken = [];

        foreach ($heads as $head) {
            $run = $this->runs->latestFull($head['chain']);

            if ($run === null) {
                $missing[] = $head['chain']->toString();

                continue;
            }

            if (! $run['intact']) {
                $broken[] = sprintf('%s (%s)', $head['chain']->toString(), $run['finished_at']->format(DATE_ATOM));

                continue;
            }

            $ageHours = ($now->getTimestamp() - $run['finished_at']->getTimestamp()) / 3600;

            if ($ageHours > $maxAgeHours) {
                $missing[] = sprintf('%s (last %s)', $head['chain']->toString(), $run['finished_at']->format(DATE_ATOM));
            } else {
                $recent++;
            }
        }

        if ($broken !== []) {
            return Check::fail('verification_recency', 'Full verification', 'Last full verification found violations: '.implode('; ', $broken).'.', 'Investigate immediately; the chain has been tampered with or a bug corrupted it.');
        }

        if ($missing !== []) {
            return Check::warn('verification_recency', 'Full verification', sprintf('%d of %d chain(s) without an intact full verification in the last %d h: %s.', count($missing), count($heads), $maxAgeHours, implode('; ', array_slice($missing, 0, 5)).(count($missing) > 5 ? ', …' : '')), 'Schedule `php artisan audit:verify` (at least daily, onOneServer).');
        }

        return Check::ok('verification_recency', 'Full verification', sprintf('All %d chain(s) fully verified and intact within the last %d h.', $recent, $maxAgeHours));
    }

    private function checkTails(): Check
    {
        $tail = max(1, (int) $this->config->get('audit.doctor.tail_events', 200));

        try {
            $heads = $this->heads();
            $verifier = $this->container->make(ChainVerifier::class);
        } catch (Throwable $e) {
            return Check::fail('tail_verification', 'Tail verification', 'Could not verify: '.$e->getMessage());
        }

        if ($heads === []) {
            return Check::skip('tail_verification', 'Tail verification', 'No chains yet.');
        }

        $events = 0;
        $broken = [];

        foreach ($heads as $head) {
            if ($head['last_sequence'] === 0) {
                continue;
            }

            $from = max(1, $head['last_sequence'] - $tail + 1);
            $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $report = $verifier->verify($head['chain'], $from);
            $this->runs->record($report, VerificationRuns::SOURCE_DOCTOR, $startedAt);
            $events += $report->eventsChecked;

            if (! $report->isIntact()) {
                $broken[] = sprintf('%s (%d violation(s))', $head['chain']->toString(), count($report->violations));
            }
        }

        if ($broken !== []) {
            return Check::fail('tail_verification', 'Tail verification', 'Violations in: '.implode('; ', $broken).'.', 'Run `php artisan audit:verify --chain=…` for the full report.');
        }

        return Check::ok('tail_verification', 'Tail verification', sprintf('Last %d event(s) of every chain verified now: %d events, all intact.', $tail, $events));
    }

    private function checkMiddlewarePriority(): Check
    {
        try {
            $router = $this->container->make(Router::class);
        } catch (Throwable) {
            return Check::skip('middleware_priority', 'Tenant middleware priority', 'No router bound.');
        }

        $priority = $router->middlewarePriority;
        $resolve = array_search(ResolveTenant::class, $priority, true);
        $bindings = array_search(SubstituteBindings::class, $priority, true);

        if ($resolve === false || $bindings === false || $resolve > $bindings) {
            return Check::fail('middleware_priority', 'Tenant middleware priority', 'ResolveTenant is not ordered before SubstituteBindings.', 'Implicit route model binding of tenant-owned models would run without a tenant. The TenantServiceProvider registers the priority; something overwrote it (e.g. a later `->priority([...])`).');
        }

        return Check::ok('middleware_priority', 'Tenant middleware priority', 'ResolveTenant runs before SubstituteBindings.');
    }

    private function checkTenantCache(): Check
    {
        $enabled = filter_var($this->config->get('tenant.cache.enabled', true), FILTER_VALIDATE_BOOL);

        if (! $enabled) {
            return Check::ok('tenant_cache', 'Tenant lookup cache', 'Disabled; every request resolves the tenant from the database.');
        }

        $store = $this->config->get('tenant.cache.store');
        $store = is_string($store) && $store !== '' ? $store : (string) $this->config->get('cache.default', '');
        $driver = (string) $this->config->get('cache.stores.'.$store.'.driver', '');

        if ($this->environment() === 'production' && in_array($driver, ['array', 'null'], true)) {
            return Check::warn('tenant_cache', 'Tenant lookup cache', sprintf('Store "%s" uses the %s driver in production.', $store, $driver), 'The negative cache against identifier scanning is ineffective; use redis, memcached or database.');
        }

        return Check::ok('tenant_cache', 'Tenant lookup cache', sprintf('Store "%s" (%s), TTL %ds, negative TTL %ds.', $store, $driver !== '' ? $driver : 'unknown', (int) $this->config->get('tenant.cache.ttl', 60), (int) $this->config->get('tenant.cache.negative_ttl', 5)));
    }

    /**
     * @return list<array{chain: ChainKey, last_sequence: int, updated_at: ?DateTimeImmutable}>
     */
    private function heads(): array
    {
        $rows = $this->connection()->table($this->table('heads_table', 'audit_chain_heads'))->orderBy('chain_key')->get(['chain_key', 'last_sequence', 'updated_at']);
        $heads = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $updatedAt = $data['updated_at'] ?? null;

            $heads[] = [
                'chain' => ChainKey::fromString((string) $data['chain_key']),
                'last_sequence' => (int) $data['last_sequence'],
                'updated_at' => is_string($updatedAt) && $updatedAt !== '' ? new DateTimeImmutable($updatedAt) : null,
            ];
        }

        return $heads;
    }

    private function connection(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }

    private function table(string $key, string $default): string
    {
        $value = $this->config->get('audit.'.$key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function environment(): string
    {
        if ($this->container instanceof Application) {
            $environment = $this->container->environment();

            return is_string($environment) ? $environment : 'unknown';
        }

        return (string) $this->config->get('app.env', 'unknown');
    }
}
