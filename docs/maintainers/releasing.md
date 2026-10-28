# Releasing

A release of this framework is a signed git tag plus the artefacts the
`Release` workflow derives from it. Nothing is built by hand; what is
downloadable is traceable to the tag, and the tag to a maintainer's key.

## Support matrix

| | Supported (hard CI gates) |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 13 |
| Databases | PostgreSQL 14–17, MySQL 8.0/8.4, MariaDB 10.11–11 |
| Tests only | SQLite |

"Supported" means: the combination is a required job in
`.github/workflows/gates.yml` and a release is not tagged while it is red.
There is no experimental matrix; a new version becomes supported by becoming a
gate ([Testing](testing.md#adding-a-database-php-or-laravel-version)), with
this table, the README and the CHANGELOG updated in the same commit.

Only the latest minor release receives fixes (see `SECURITY.md`).

## Versioning

Semantic versioning on the meta package and all three packages together
(they are released as one unit from one tag).

- **Patch**: fixes, no schema change, no behaviour change under the documented guarantees.
- **Minor**: new features, additive migrations only (new tables/columns with defaults; never a change to an existing hashed column).
- **Major**: anything that changes what is hashed (`HashableRecord::VERSION`), removes configuration keys or changes fail-closed defaults. A major ships with a migration guide in `docs/`.

Pre-releases carry a suffix (`1.0.0-alpha.2`, `1.0.0-rc.1`); the workflow marks
them as pre-release on GitHub.

## Cutting a release

1. **Green everywhere.** On `main`: CI green including `mutation`; locally
   `./start-security-framework.sh check`, `matrix` and `mutate` green in
   Docker.
2. **Changelog.** Rename the top section from `## x.y.z – unreleased` to
   `## x.y.z – YYYY-MM-DD` and read it once as a user would. The release
   workflow refuses a tag without a matching `## x.y.z` section and uses it as
   the release notes.
3. **Commit** that change (`chore(release): x.y.z`) and push; wait for CI.
4. **Sign the tag.** Tags are annotated and signed; the workflow refuses
   anything else (see *Trust root* below).

   ```bash
   # once: git config --global user.signingkey <key-id>   (OpenPGP)  or
   #       git config --global gpg.format ssh && git config --global user.signingkey ~/.ssh/id_ed25519.pub
   git tag -s vX.Y.Z -m "security-framework X.Y.Z"
   git verify-tag vX.Y.Z
   git push origin vX.Y.Z
   ```

   Register the same public key with the GitHub account so the tag also shows as *Verified* in the UI.
5. **Watch the `Release` workflow.** Its first job checks that the tag is
   annotated and that its signature verifies against the committed trust root;
   only then do the quality gates run on the tagged commit — the same reusable
   workflow (`.github/workflows/gates.yml`) that CI runs on `main`: audit, Pint,
   PHPStan, the test matrix on all four databases and supported versions, the
   coverage floor and the mutation gate. A release therefore takes as long as a
   CI run (about 50 minutes on the 2-vCPU runners of a private repository); a
   tag cannot be published from a commit that would not pass CI, whatever `main`
   looked like at the time. Then it publishes:

   | Asset | What it is |
   | --- | --- |
   | `security-framework-vX.Y.Z.tar.gz` | `git archive` of the tag: same tag, same bytes; contains no `vendor/` |
   | `security-framework-vX.Y.Z.spdx.json`, `.cdx.json` | SBOM of the **runtime** dependency tree (installed with `--no-dev`), SPDX and CycloneDX |
   | `SHA256SUMS` | checksums of the above |
   | provenance attestation | SLSA build provenance for the archive and the SBOMs, signed by GitHub's OIDC identity of this workflow — **public repositories only** (see below) |

6. **Announce** in the CHANGELOG-derived release notes only; no separate notes to keep in sync.
7. **Refresh the translations** (`docs/de/`, `docs/sv/`, `README.de.md`,
   `README.sv.md`) after the release, not before it: `bin/docs-check translations`
   lists every page whose English source changed since it was translated
   (drift is a warning in CI, never a failure). Update those pages from the
   English text, then `bin/docs-check rehash <page>…` writes the new source
   hash into their headers; `bin/docs-check` must be clean again. Code blocks
   are never translated — the check fails when they differ from the source.
   Swedish pages go past a Swedish-speaking reviewer before they are committed.

### Private repositories

GitHub issues artifact attestations for public repositories on every plan, but
for private and internal repositories only on GitHub Enterprise Cloud. The
workflow therefore skips the attestation step (and the `gh attestation verify`
hint in the release notes) unless the repository is public; everything else —
signed tag, gates, archive, SBOMs, checksums — is produced the same way. While
the repository is private, the chain of custody is: signed tag → workflow run
(visible in the Actions log of the tag) → `SHA256SUMS` in the release. Making
the repository public later turns the attestation on without a change to the
workflow.

## Trust root for tag signatures

`gh release create --verify-tag` only checks that the tag exists on the
remote; it does not check a signature. The `verify-tag` job therefore runs
`git verify-tag` against keys that are committed to the repository, so the set
of people who can cut a release is itself version-controlled and reviewable:

- **SSH-signed tags**: add one line per maintainer to `.github/allowed_signers`
  (`<git e-mail> namespaces="git" ssh-ed25519 AAAA…`). The e-mail must match the
  tagger identity.
- **OpenPGP-signed tags**: export the public keys to `.github/release-keys.gpg`
  (`gpg --export --armor <key-id> >> .github/release-keys.gpg`). The job imports
  them, trusts them ultimately and additionally insists on `GOODSIG` +
  `TRUST_FULLY|ULTIMATE`, so a signature by any other key fails.

With neither file populated the workflow stops before building anything. Key
rotation: add the new key, release once, remove the old key in a later commit.
A change to either file is a security-relevant change and gets the same review
as code.

On GitHub, additionally protect `v*` tags with a ruleset (only maintainers may
create them, signatures required), so a tag that never reaches the workflow
cannot be created by anyone else either.

## Verifying a release (consumers)

```bash
git config gpg.ssh.allowedSignersFile .github/allowed_signers     # or: gpg --import .github/release-keys.gpg
git verify-tag vX.Y.Z                                             # maintainer signature against the committed trust root
sha256sum -c SHA256SUMS                                           # integrity of the downloads
gh attestation verify security-framework-vX.Y.Z.tar.gz --repo <owner>/security-framework   # public repository: built by this repo's workflow from this tag
```

Composer installs from the tag, not from the archive; the archive and the
SBOM exist so that what runs can be compared with what was reviewed.

## Supply-chain rules for the repository

- GitHub Actions are pinned to commit SHAs with the version in a comment; bumps
  are separate commits (`chore(ci): bump actions/checkout to v7.0.2`).
- `composer audit` runs in every CI run and in `bin/check`; an advisory with a
  fix blocks the release, one without a fix is recorded in the CHANGELOG.
- `composer.lock` is not committed (this is a library). Reproducibility is
  provided by the tag (source) and the SBOM (the exact tree a release was
  verified with); a consumer pins the framework version in their own lock file.
- Dependabot watches the workflow files and the four `composer.json` files
  (`.github/dependabot.yml`, weekly; minor and patch bumps grouped, majors on
  their own); a dependency bump goes through the full gates like any other change.

## Upgrading and rolling back (consumers)

- Read the CHANGELOG section of every version you skip; breaking changes and
  new configuration keys are listed there first.
- Run `php artisan migrate` after the update. Migrations are additive within a
  major version; existing audit rows are never rewritten (the triggers would
  refuse it anyway).
- Run `php artisan security:doctor --strict` after deploying; new checks may
  surface configuration the previous version did not inspect.
- **Rollback** is a `composer require efnext/security-framework:<previous>`
  plus a deploy. Additive migrations from the newer version can stay in place;
  do not roll back migrations that created audit tables while events have been
  written to them. Chains written by a newer patch or minor version verify
  under the older one, because the hash version only changes in a major.
- A major upgrade that bumps the hash version keeps verifying old chains with
  the old scheme (`hash_version` is stored per event); the migration guide of
  that major says what else to do.

## Security releases

A fix for a reported vulnerability (see `SECURITY.md`) is released as a patch
of the latest minor, with the advisory published after the release is
available. The CHANGELOG entry names the affected versions and the CVE or
GHSA id once assigned.
