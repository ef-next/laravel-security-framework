# Testing the framework

Maintainer documentation: how the quality gates are run locally and in CI, and
how to read and extend them. Operators only need `security:doctor`
(`docs/en/operations.md`); integrators need the checklist
(`docs/en/integration-checklist.md`).

## Everything runs in Docker

The host needs nothing but Docker Desktop. One script brings up the databases
(PostgreSQL 16, MySQL 8.4, MariaDB 11 with health checks), builds the PHP image
(`docker/php/Dockerfile`, `PHP_VERSION` build argument, 8.3 by default),
installs Composer dependencies when needed and runs the gates:

```bash
./start-security-framework.sh                 # check: composer audit + selfcheck + Pint + PHPStan + tests on sqlite, pgsql, mysql, mariadb
./start-security-framework.sh test mysql      # one database
./start-security-framework.sh test sqlite --filter=BelongsToTenant
./start-security-framework.sh concurrency     # forked writers against pgsql, mysql, mariadb
./start-security-framework.sh matrix          # the suite under PHP 8.3, 8.4 and 8.5
./start-security-framework.sh mutate          # mutation testing on the security core
./start-security-framework.sh load mysql      # 48 forked writers on two chains (AUDIT_LOAD_WRITERS, AUDIT_LOAD_EVENTS)
./start-security-framework.sh example pgsql   # examples/laravel-app on a fresh Laravel skeleton, framework from this checkout
./start-security-framework.sh shell           # bash inside the PHP container
./start-security-framework.sh down            # stop and remove the containers

PHP_VERSION=8.5 ./start-security-framework.sh check          # another PHP image
LARAVEL=13 ./start-security-framework.sh install             # resolve against Laravel 13, then test
DEPS=lowest ./start-security-framework.sh install            # lowest allowed dependency versions, then test
```

The building blocks it orchestrates are `bin/composer`, `bin/test`, `bin/check`,
`bin/mutate` and `examples/laravel-app/install.sh`; `bin/docs-check` (Python 3,
no dependencies) runs the documentation gates and works on the host as well.
Output is mirrored to `.claude/*.txt` (gitignored) so that a run can be read
after the fact. `composer.lock` is not committed (this is a
library); the first `install` creates it locally.

Two things the databases need for the audit triggers: MySQL/MariaDB with binary
logging require `log_bin_trust_function_creators=1` or a user with `SUPER`
(`compose.yaml` sets the server variable; CI uses `root`), and the test user
must be allowed to create triggers.

## The gates

`.github/workflows/gates.yml` is the single definition; `ci.yml` runs it on
every push and pull request, `release.yml` runs it for every version tag after
the tag signature has been verified. A change that makes CI red therefore also
makes a release impossible.

| Job | Command | Gate |
| --- | --- | --- |
| `quality` | `composer audit`, `pint --test`, `phpstan analyse` (level 8) | no advisories, no style drift, no type errors |
| `tests` | `pest --coverage --min=80` on PHP 8.3/8.4 × Laravel 12 × sqlite/pgsql/mysql/mariadb, plus PHP 8.3 with `--prefer-lowest` on every database | the suite passes everywhere in the support matrix; line coverage ≥ 80 % |
| `databases` | `pest` on PostgreSQL 14 and 17, MySQL 8.0, MariaDB 10.11 and 11.4 | the oldest and newest supported database versions behave the same |
| `mutation` | `bin/mutate --min=70`, report uploaded as the `mutation-report` artifact | the tests notice changes to the security core |
| `docs` | `bin/docs-check` (relative links and anchors; every `env()` of the package configs in `.env.example` and the configuration reference; every Artisan command, exception and event class in its reference page; every non-fragment `php` block parses; translation source headers) and mermaid-cli over `docs/en/diagrams/*.md` | the documentation matches the code and renders |
| `example` | `examples/laravel-app/install.sh` on a fresh `laravel/laravel` skeleton with the framework installed from the checkout, once on SQLite and once on PostgreSQL: the example's tests (against that database, real triggers included), `audit:anchor`, `audit:verify`, `security:doctor` | the documented integration path works end to end on a real application |
| `experimental` (ci.yml only, `continue-on-error`) | `pest` on PHP 8.5 × Laravel 12 and Laravel 13 combinations | observation, not a gate; a row that stays green for a minor release cycle moves into `tests` |

Runners of a private repository have 2 vCPUs; the mutation job takes about
50 minutes there, the rest a few minutes each. CI cancels a superseded run of
the same branch; a release run is never cancelled.

## Suites worth knowing

- `packages/*/tests/Feature` and `Unit` — behaviour per package; the `*EdgeCasesTest`, `*BoundariesTest` and `DefensiveBranchesTest` files hold the tests that mutation testing asked for.
- `RandomisedInvariantsTest` — property-style tests for canonical JSON, hashing, identifiers, tenant ids and the sanitiser. Replay a failure with `RANDOM_SEED=<n>`, soak with `RANDOM_ITERATIONS=<n>`.
- `HashVectorsTest` with `packages/audit/tests/Fixtures/hash-vectors.json` — known-answer vectors for the hash format. If this suite fails, you changed the format: that is a new `HashableRecord::VERSION`, a migration note and a CHANGELOG entry under "breaking", never a quiet edit of the vectors.
- `ConcurrencyTest` (`--group=concurrency`) and the opt-in load test (`--group=load`) — forked writers on real databases; they are what found the InnoDB deadlocks and MariaDB's snapshot-isolation error.
- `tests/Arch/ArchitectureTest.php` — dependency direction between the packages and a few "never" rules.

## Mutation testing

Pest rewrites one expression of the security core at a time (an operator, a
constant, a removed call) and reruns the tests that cover it; a mutant
"survives" when no test fails. Coverage is what maps mutants to tests, and
Pest collects it in worker processes it spawns itself: `bin/mutate` therefore
enables pcov (or xdebug's coverage mode) through the environment for every
child process and refuses to run when no driver is active there — a run whose
workers lack coverage would report every mutant as "uncovered" and a score of
0 %, which is a broken setup, not a result. `MUTATE_PATHS=<dir,dir>` narrows a
run to part of the core, `MUTATE_PARALLEL=0` uses one process.

The score is a floor for the core, not a target of 100 %. After the review
rounds of 1.0.0-alpha.2 it stands at **about 75–77 %** (77.38 % of 2715
mutants measured independently on the `v1.0.0-alpha.2` tag; a reference run on
23 Sep 2026 with the Docker image `php:8.3-cli-alpine`, Pest 3.8 /
pest-plugin-mutate 3.0 and 10 worker processes: 2603 mutants, 1864 killed,
541 survived, 107 not covered by any test, 91 timed out — killed and timed-out
mutants both count as detected, and their split moves with CPU and
parallelism). CI fails below `--min=70`. Of the survivors, roughly a fifth are
string mutations inside `security:doctor` messages and another fifth are
numeric constants (timing, limits).

Two limitations of the tool to keep in mind when reading a report:

- **Constant declarations are always "uncovered".** pcov cannot attribute a
  test to a line without executable code, so mutants of `HashableRecord::VERSION`
  or `CanonicalJson::FLAGS` show up as uncovered even though `HashVectorsTest`
  fails the moment they change. `covers()` does not change that; the plugin
  selects tests by line coverage only.
- **Every run tests every mutant.** The plugin's cache remembers generated
  mutants and last results for ordering, not for skipping; caching it in CI
  does not shorten a run. Sharding the job across runners would.

Before treating a survivor as a gap, check whether it falls into one of the
categories that are deliberately left alone:

- **Defence in depth.** Many checks exist twice on purpose: model hooks *and*
  the SQL constraint in `setKeysForSaveQuery()`, the query-level guard *and*
  the composite foreign key, schema defaults *and* explicit values in inserts.
  Removing one layer leaves the other, so the mutant survives. That is the
  point of the second layer; do not remove it to please the score.
- **Timing and back-off.** Jitter ranges, `usleep()` factors and retry
  defaults that are configuration-driven (`audit.write.attempts`) are not
  asserted exactly.
- **Diagnostic detail.** Some entries in exception messages and doctor
  details are informational; the tests pin the reason codes and the numbers an
  operator acts on, not every string.
- **Framework-normalised input.** Casts that Laravel or the database already
  perform (`(int)` on integer columns, `(string)` on string ids) survive on the
  drivers the suite runs.

Everything else is a real gap: add a test that fails with the mutant (the
`*EdgeCasesTest` and `*BoundariesTest` files are where such tests live), rerun
`start-security-framework.sh mutate`, and raise the `--min` of the mutation job
in `.github/workflows/gates.yml` when the score has moved up for good.

## Adding a database version or a PHP version

1. Add the row to `gates.yml` (`tests` or `databases` matrix) or, for a
   combination without a support promise yet, to the `experimental` matrix in
   `ci.yml`.
2. Add the service image to `compose.yaml` if a local run should cover it.
3. Update the support matrix in `README.md` ("Compatibility"),
   `docs/maintainers/releasing.md` and the CHANGELOG in the same commit.
