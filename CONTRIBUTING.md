# Contributing

Thank you for helping to make this framework more trustworthy. This page is for
people (and agents) who change the framework itself; integrating it into an
application is covered by the [README](README.md) and `docs/en/`.

## Ground rules

- **Fail closed.** A missing tenant context, a missing signing key, an
  unsupported database, an unreadable anchor store — the framework refuses
  rather than degrades. A change that turns a refusal into a warning needs a
  written reason in the pull request and in `docs/en/what-it-does-not-do.md`.
- **Two layers stay two layers.** Model hooks *and* SQL constraints, query
  guards *and* composite foreign keys, signatures *and* anchors: do not remove
  one because the other "already covers it" (mutation testing will show the
  surviving mutant; that survivor is intentional).
- **The hash format is a contract.** Anything that changes the bytes that go
  into an event hash — the field set in `HashableRecord`, the canonical JSON
  rules, the domain string, the HMAC construction — is a new
  `HashableRecord::VERSION`, a migration note and a breaking-change entry in the
  CHANGELOG. `HashVectorsTest` fails on such a change by design; never "fix" it
  by regenerating `packages/audit/tests/Fixtures/hash-vectors.json` in the same
  pull request without that version bump.
- **English is the source language.** Code, configuration keys, messages and
  `docs/en/` are written first; `docs/de/` and `docs/sv/` are translations that
  follow a release and carry the commit of the English text they correspond to.

## Development

Everything runs in Docker; nothing but Docker Desktop is needed on the host:

```bash
./start-security-framework.sh                 # composer audit + selfcheck + Pint + PHPStan + tests on sqlite, pgsql, mysql, mariadb
./start-security-framework.sh test pgsql --filter=HashChainVerifier
./start-security-framework.sh mutate          # mutation testing on the security core
./start-security-framework.sh shell           # bash inside the PHP container
```

All modes, the CI gates and how to read a mutation report are described in
[docs/maintainers/testing.md](docs/maintainers/testing.md). A pull request is
expected to be green on `./start-security-framework.sh check` and
`bin/docs-check` before it is opened; CI then runs the full matrix (about
50 minutes, most of it mutation testing) plus the documentation and example
application gates.

## What a change needs

A pull request touches, in the same change:

| If it … | then it also … |
| --- | --- |
| adds or changes a config key or environment variable | updates the config file's comment, `.env.example`, `docs/en/reference/configuration.md` and, if operators need to act, `docs/en/operations.md` (`bin/docs-check` fails on a missing `.env.example` or reference entry) |
| adds or changes a public API, trait, middleware alias, event, exception or Artisan command | updates the matching page in `docs/en/reference/` (`api.md`, `events.md`, `exceptions.md`, `artisan-commands.md`), the guide that uses it, the README walk-through when it is part of the ten-minute path, and the CHANGELOG |
| changes a security guarantee or one of its conditions | updates the "Guarantees and their conditions" table in the README, `docs/en/what-it-does-not-do.md` and, if the attacker model moves, `docs/en/threat-model.md` |
| changes what `security:doctor` checks or how `--strict` judges | updates `docs/en/operations.md` ("Doctor") and the integration checklist |
| changes the support matrix (PHP, Laravel, database versions) | updates `README.md` ("Compatibility"), `docs/maintainers/releasing.md` and `.github/workflows/gates.yml` |
| changes the hash format or verification | follows the hash-format rule above |
| changes tenant resolution, the audit write path, anchoring or trust boundaries | updates the affected page in `docs/en/diagrams/` (and its "In words" section) and `docs/en/architecture.md` |
| changes an integration step, a default or a refusal that an application would notice | updates `examples/laravel-app` (its tests are a CI gate) and `AGENTS.md` |
| changes English documentation that has a translation | leaves the translation alone but expects `bin/docs-check` to report drift; translations are refreshed after the release |

Tests: every behaviour change comes with a test that fails without it. Security
edge cases live in the `*EdgeCasesTest`, `*BoundariesTest` and
`DefensiveBranchesTest` files; property-style tests in
`RandomisedInvariantsTest`. New code is expected to keep the mutation score of
the security core at or above the CI floor (`--min=70`).

## Style

- PHP 8.3+, `declare(strict_types=1)`, final classes unless extension is a
  documented feature, PHPStan level 8 without baseline entries or `@phpstan-ignore`.
- Pint with the repository's `pint.json`; run `vendor/bin/pint` before committing.
- Conventional commits: `feat(audit): …`, `fix(tenant): …`, `docs: …`,
  `chore(ci): …`, `chore(release): x.y.z`. The body says *why*; the CHANGELOG
  entry says what it means for a user.
- Exceptions and doctor messages say what is wrong *and* what to do about it;
  an agent or an operator acts on that text without reading the code.

## Releases

Maintainers cut releases from signed tags; the process, the trust root for tag
signatures and the support policy are in
[docs/maintainers/releasing.md](docs/maintainers/releasing.md). Only the latest
minor release receives fixes ([SECURITY.md](SECURITY.md)).

## Reporting a vulnerability

Not through an issue or a pull request: see [SECURITY.md](SECURITY.md).

## Licence

By contributing you agree that your contribution is licensed under the
[MIT licence](LICENSE) of this repository.
