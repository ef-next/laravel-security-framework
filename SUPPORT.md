# Support

## Before you ask

1. `php artisan security:doctor` — every FAIL and WARN line names what is wrong
   and what to change. Its `--json` output belongs in every report.
2. [Troubleshooting](docs/en/guides/troubleshooting.md) — by symptom: HTTP
   status codes, exceptions, migration errors, doctor findings, verifier
   reasons.
3. [What it does not do](docs/en/what-it-does-not-do.md) — the boundaries; a
   good number of questions are answered by "that layer is yours".
4. The [reference](docs/en/reference/configuration.md) for a configuration key,
   an [Artisan command](docs/en/reference/artisan-commands.md), an
   [exception](docs/en/reference/exceptions.md) or an [event](docs/en/reference/events.md).

## Where to ask

| You have … | Go to |
| --- | --- |
| a reproducible bug | a [bug report](https://github.com/ef-next/laravel-security-framework/issues/new?template=bug.yml) — include the doctor output, the framework and Laravel versions, the database and driver version, and the smallest test that shows it |
| a doctor FAIL you cannot resolve, or a deployment question | a [misconfiguration report](https://github.com/ef-next/laravel-security-framework/issues/new?template=misconfiguration.yml) |
| a feature idea or a missing guarantee | a [feature request](https://github.com/ef-next/laravel-security-framework/issues/new?template=feature.yml) — say which guarantee it strengthens or which integration it makes safer |
| a **security vulnerability** | **not an issue.** [SECURITY.md](SECURITY.md): security@ef-next.com, acknowledgement within two business days |
| a question about integrating it with an AI agent | [AGENTS.md](AGENTS.md) is the contract; open a feature request if the contract is missing something |

Commercial support, integration work and audits: ef.next AB, info@ef-next.com.

## What to include

```bash
php artisan security:doctor --json
composer show efnext/security-framework laravel/framework | grep -E '^(name|versions)'
php -v | head -1
```

and, for anything about the audit log, the output of `php artisan audit:verify`
for the affected chain. Never paste signing keys, `APP_KEY` or anchor
credentials; the doctor's output does not contain them.

## Supported versions

Only the latest minor release receives fixes (see
[Compatibility](docs/en/reference/compatibility.md) for PHP, Laravel and
database versions). Older releases: upgrade first, then report.
