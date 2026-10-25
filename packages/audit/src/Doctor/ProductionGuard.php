<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Doctor;

use Efnext\Security\Audit\Anchoring\DatabaseAnchorStore;
use Efnext\Security\Audit\Anchoring\LogAnchorStore;
use Efnext\Security\Audit\Anchoring\NullAnchorStore;
use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Exceptions\InsecureConfiguration;
use Efnext\Security\Audit\Exceptions\InvalidSigningKey;
use Efnext\Security\Audit\Signing\Keyring;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Throwable;

/**
 * Configuration-only checks that need no database: the ones that are cheap
 * enough to run at boot and unambiguous enough to refuse booting in
 * production. security:doctor reports the same problems (and more).
 */
final class ProductionGuard
{
    public const array SUPPORTED_DRIVERS = ['pgsql', 'mysql', 'mariadb'];

    /** Store classes that can never satisfy a readability requirement: write-only or discarding. */
    private const array NOT_READABLE = [LogAnchorStore::class, NullAnchorStore::class];

    /**
     * @param  FilesystemFactory|null  $filesystems  when given, the filesystem anchor store's disk is
     *                                               resolved (not read) instead of only looked up in the configuration
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ?FilesystemFactory $filesystems = null,
    ) {}

    /**
     * @throws InsecureConfiguration
     */
    public function enforce(): void
    {
        $problems = $this->problems();

        if ($problems !== []) {
            throw InsecureConfiguration::because($problems);
        }
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        try {
            if ($this->keyring()->isEmpty()) {
                $problems[] = 'No audit signing key is configured (AUDIT_SIGNING_KEYS / AUDIT_SIGNING_ACTIVE_KEY); chains would be rewritable by anyone with database access.';
            }
        } catch (InvalidSigningKey $e) {
            $problems[] = 'The audit signing key configuration is invalid: '.$e->getMessage();
        }

        $driver = $this->auditDriver();

        if ($driver === 'sqlite') {
            $problems[] = 'The audit tables live on SQLite, which cannot provide the row locking and durability the audit log relies on.';
        } elseif (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            $problems[] = sprintf('The audit database driver "%s" is not supported (no append-only triggers).', $driver);
        }

        if (! $this->bool('audit.enforce_append_only', true)) {
            $problems[] = 'Append-only enforcement is switched off (AUDIT_ENFORCE_APPEND_ONLY=false); audit rows could be updated and deleted.';
        }

        if ($this->readableAnchorStores() === []) {
            $problems[] = 'No readable anchor store is configured (audit.anchoring.stores must contain database, filesystem or a readable custom store; log is write-only).';
        } elseif ($this->requiresExternalAnchor()) {
            array_push($problems, ...$this->externalAnchorProblems());
        }

        return $problems;
    }

    /**
     * Whether production must anchor outside the audit database
     * (audit.production.require_external_anchor / AUDIT_REQUIRE_EXTERNAL_ANCHOR).
     */
    public function requiresExternalAnchor(): bool
    {
        return $this->bool('audit.production.require_external_anchor', false);
    }

    /**
     * Readable anchor stores that do not live in the audit database: the ones
     * that make a rewrite by a database-level attacker detectable.
     *
     * @return list<string>
     */
    public function externalAnchorStores(): array
    {
        return array_values(array_filter(
            $this->readableAnchorStores(),
            static fn (string $name): bool => $name !== 'database' && ! (class_exists($name) && is_a($name, DatabaseAnchorStore::class, true)),
        ));
    }

    /**
     * Why the configured external anchor stores would not work. Configuration
     * (plus resolving the filesystem disk when a factory was given): the
     * filesystem store needs a disk that exists, a custom store must be a class
     * implementing AnchorStore. The service provider additionally instantiates
     * the stores at boot; security:doctor reads from them.
     *
     * @return list<string>
     */
    public function externalAnchorProblems(): array
    {
        $external = $this->externalAnchorStores();

        if ($external === []) {
            return ['AUDIT_REQUIRE_EXTERNAL_ANCHOR is on but audit.anchoring.stores has no readable store outside the database (add filesystem — object storage with object lock — or a custom store, listed first).'];
        }

        $problems = [];

        foreach ($external as $name) {
            if ($name === 'filesystem') {
                $disk = $this->config->get('audit.anchoring.filesystem.disk');

                if (! is_string($disk) || $disk === '') {
                    $problems[] = 'The filesystem anchor store has no disk configured (AUDIT_ANCHOR_DISK / audit.anchoring.filesystem.disk).';
                } elseif ($this->filesystems instanceof FilesystemFactory) {
                    try {
                        $this->filesystems->disk($disk);
                    } catch (Throwable $e) {
                        $problems[] = sprintf('The filesystem anchor store points at disk "%s", which cannot be set up: %s', $disk, $e->getMessage());
                    }
                } elseif (! is_array($this->config->get('filesystems.disks.'.$disk))) {
                    $problems[] = sprintf('The filesystem anchor store points at disk "%s", which is not defined in config/filesystems.php.', $disk);
                }

                continue;
            }

            if (! class_exists($name) || ! is_a($name, AnchorStore::class, true)) {
                $problems[] = sprintf('Anchor store "%s" is neither a built-in store nor a class implementing %s.', $name, AnchorStore::class);
            } elseif (is_a($name, DatabaseAnchorStore::class, true)) {
                $problems[] = sprintf('Anchor store "%s" stores anchors in the audit database and does not count as external.', $name);
            }
        }

        return $problems;
    }

    public function keyring(): Keyring
    {
        return Keyring::fromString(
            (string) $this->config->get('audit.signing.keys', ''),
            (string) $this->config->get('audit.signing.active_key', ''),
        );
    }

    public function auditConnectionName(): string
    {
        $configured = $this->config->get('audit.connection');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return (string) $this->config->get('database.default', '');
    }

    public function auditDriver(): string
    {
        $driver = $this->config->get('database.connections.'.$this->auditConnectionName().'.driver');

        return is_string($driver) ? $driver : 'unknown';
    }

    /**
     * Configured anchor store names that can be read back by the verifier.
     *
     * @return list<string>
     */
    public function readableAnchorStores(): array
    {
        /** @var list<string> $names */
        $names = array_values(array_filter((array) $this->config->get('audit.anchoring.stores', ['database']), 'is_string'));

        return array_values(array_filter($names, static fn (string $name): bool => $name !== 'log' && ! self::isNotReadable($name)));
    }

    /**
     * Whether a configured store name denotes a store that discards or never returns anchors
     * (the log store, the null store, or a class derived from them).
     */
    public static function isNotReadable(string $name): bool
    {
        foreach (self::NOT_READABLE as $class) {
            if ($name === $class || (class_exists($name) && is_a($name, $class, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function configuredAnchorStores(): array
    {
        /** @var list<string> $names */
        $names = array_values(array_filter((array) $this->config->get('audit.anchoring.stores', ['database']), 'is_string'));

        return $names;
    }

    private function bool(string $key, bool $default): bool
    {
        return filter_var($this->config->get($key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
