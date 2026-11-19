# Release process

## Table of contents

- [Version plan](#version-plan)
- [Before each 0.x tag and 1.0.0](#before-each-0x-tag-and-100)
- [Standard workflow](#standard-workflow)

## Version plan

Contract QC-13: **0.1.0** core (domain, storage, sources, sync, console, Scheduler bridge, Twig; batched audit),
**0.2.0** admin UI on UiKit + FormKit (immediate audit), **1.0.0** after `bundle-req-audit` 100 % and SEC-004.
The owner registers the package on Packagist before the first tag.

## Before each 0.x tag and 1.0.0

1. The security audit of the slice recorded (batched for 0.1.0, immediate for 0.2.0); every blocking finding fixed.
2. [CHANGELOG.md](CHANGELOG.md) — move `[Unreleased]` to `## [0.x.0] - YYYY-MM-DD`.
3. [UPGRADING.md](UPGRADING.md) — the section for that version current (schema changes with their DDL).
4. [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) — audited revision set to the tag.
5. `make release-check` green.
6. CI green on the exact release commit (including the "Databases" job).
7. Annotated tag `git tag -a v0.x.0 -m "Release v0.x.0"`; run `make check-no-cursor-coauthor` before pushing.
8. Push branch and tag; `release.yml` creates the GitHub Release from the tag message and the changelog entry.

## Standard workflow

1. Move `[Unreleased]` entries in [CHANGELOG.md](CHANGELOG.md) to a new `## [x.y.z] - YYYY-MM-DD` section.
2. Update [UPGRADING.md](UPGRADING.md) when hosts must act.
3. Update [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md) and [SECURITY.md](SECURITY.md) when behaviour
   changes.
4. Run `make release-check`, commit, tag, push branch and tag; verify the GitHub Release and Packagist.
