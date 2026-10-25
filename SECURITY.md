# Security policy

## Reporting

Report suspected vulnerabilities to security@ef-next.com. Include the package,
version, a reproduction and the impact you see. You will get an acknowledgement
within two business days.

Do not open public issues for security reports.

## Scope

In scope: anything that lets a request, job or command read or write another
tenant's data through the framework's own APIs; anything that lets audit
records be changed or removed without `audit:verify` reporting it while keys
and anchors are configured as documented; identifier generation weaknesses.

Out of scope: issues that require `withoutGlobalScope()`, raw SQL, a disabled
`enforce_append_only`, missing signing keys, database-only anchors, or the
other boundaries listed in `docs/en/what-it-does-not-do.md`.

## Supported versions

Only the latest minor release receives fixes.
