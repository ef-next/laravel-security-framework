<!-- Thanks. CONTRIBUTING.md lists what a change needs; the checklist below is that list. -->

## What and why

<!-- One paragraph: the consequence for a user, not the diff. Link the issue. -->

## Checklist

- [ ] `./start-security-framework.sh` is green (composer audit, selfcheck, Pint, PHPStan, tests on sqlite / pgsql / mysql / mariadb)
- [ ] tests: new behaviour has a test; a fixed bug has a regression test; a refusal has a test that it refuses
- [ ] `CHANGELOG.md`: an entry under the unreleased version, written for the user
- [ ] documentation: the guide, reference page (`docs/en/reference/*`) and `.env.example` entry that describe this behaviour are updated; `bin/docs-check` passes
- [ ] threat model: if this changes what an attacker can or cannot do, `docs/en/threat-model.md` and the affected diagram in `docs/en/diagrams/` say so
- [ ] hash format: `packages/audit/tests/Fixtures/hash-vectors.json` is unchanged — or this PR bumps `HashableRecord::VERSION` and says why
- [ ] no refusal was weakened into a warning, no second defence layer removed because the first one exists
- [ ] `AGENTS.md`: if an integration step or invariant changed, the agent contract says so

## Security impact

<!-- None / describe. Anything that could be a vulnerability goes to security@ef-next.com first, not into a public PR. -->
