# Spec-driven development

This document is the single entry point for **what CurrencyKitBundle guarantees**, **how it is proven** and **which
`REQ-*` anchors** the Makefile and CI carry. There is no separate executable spec language: **PHPUnit** and
**PHPStan** are the normative proof alongside the prose.

## Table of contents

- [Layers kept in sync](#layers-kept-in-sync)
- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers](#requirement-identifiers)
- [Contributor workflow](#contributor-workflow)
- [Relationship to Engram](#relationship-to-engram)
- [GitHub Spec Kit](#github-spec-kit)
- [See also](#see-also)

## Layers kept in sync

| Layer | Role | Where |
| --- | --- | --- |
| Design contract | Ports, storage, sync and conversion algorithms, security model, configuration, worker design, tests, slices | [CONTRACT.md](CONTRACT.md) |
| GitHub Spec Kit baseline | Feature spec (user scenarios, `FR-*`, `SC-*`, non-goals) and the 100 % code inventory of `src/` | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md), [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md), manual in [SPEC-KIT.md](SPEC-KIT.md) |
| Product behaviour | What integrating applications may rely on | [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [INSTALLATION.md](INSTALLATION.md), [SECURITY.md](SECURITY.md), [SOURCES.md](SOURCES.md) |
| Traceability anchors | Stable `REQ-*` ids in the Makefile and CI, so PRs and issues can cite tooling behaviour | [Requirement identifiers](#requirement-identifiers) |
| Change management | Consumer-facing deltas | [CHANGELOG.md](CHANGELOG.md), [UPGRADING.md](UPGRADING.md), [RELEASE.md](RELEASE.md) |

Every change that adds, moves or removes a source file updates the baseline spec, the code inventory and the integrator
docs in the same pull request.

## User stories

| ID | Story | Scope | Integrator docs |
| --- | --- | --- | --- |
| US-01 | **As an** integrator, **I want** to convert an amount between two currencies at a date and see the rate, its publication date and its source **so that** my page or log can show the figure honestly. | Converter, `ConvertedAmount` | [USAGE.md](USAGE.md) |
| US-02 | **As an** operator, **I want** the euro reference rates synced on a schedule, with a change log when a published value changes **so that** stored history is trustworthy. | Sync, Scheduler bridge, change log | [USAGE.md](USAGE.md#keeping-rates-current) |
| US-03 | **As an** operator, **I want** manual rates and file imports **so that** an air-gapped install or a contract rate still works. | Manual rates, import | [USAGE.md](USAGE.md#manual-rates) |
| US-04 | **As a** template author, **I want** a Twig filter that prints an amount with its reference-rate note **so that** nobody mistakes it for a payment rate. | Twig filter | [USAGE.md](USAGE.md#twig) |
| US-05 | **As a** platform maintainer, **I want** worker-mode safety and write units that survive contention **so that** the bundle runs on a kept-alive kernel under load. | Memo, write units | [CONCURRENCY.md](CONCURRENCY.md), [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) |
| US-06 | **As a** security owner, **I want** admin operations denied by default and decided by an area checker and events **so that** I control who changes rates. | Guard, access events | [SECURITY.md](SECURITY.md) |

## Bundle functional scope

**Goal:** currency conversion at reference rates for Symfony: ECB euro reference rates stored by date, decimal-string
amounts, cross rates through the euro, results that carry the rate, its date, its source and a stale flag.

**In scope (0.1.0):** the converter, the ECB and Frankfurter-compatible sources, sync, backfill and file import, manual
rates, the console commands, the Scheduler bridge, the Messenger handler, the Twig filter, the access pattern and the
audit port ([CONTRACT.md](CONTRACT.md) slice S1).

**Explicit non-goals:** payment, settlement, trading or accounting rates; cryptocurrencies and commodity codes; a
network call on the conversion path; a web UI and a public JSON API in 0.1 (the UI is planned for 0.2.0).

**Not in the Packagist API:** `tests/`, `specs/`, `.specify/`, `.cursor/` (excluded from the archive) and the
`@internal` classes.

## Validating the functional spec

```bash
make test-coverage-100   # PHPUnit (Unit, Architecture, Integration on SQLite) + 100 % statements / elements of src/
make test-with-db        # Database suite on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (REQ-TEST-005)
make phpstan igor        # PHPStan level max + FrankenPHP worker rulesets, Igor worker-state audit
make qa                  # cs-check + test
make release-check       # the whole pre-release chain
```

Coverage floor: **100 %** of `src/`. Any behaviour change ships with tests under `tests/`.

## Requirement identifiers

| `REQ-*` | Where | Meaning |
| --- | --- | --- |
| REQ-MAKE-001 / 002 | `Makefile` (`help`, `ensure-up`, `release-check`) | Standard targets, sequential release chain |
| REQ-MAKE-007 | `Makefile` `down-dev` | Stop without removing volumes |
| REQ-MAKE-004 / REQ-I18N-002 | `Makefile` `validate-translations` | YAML syntax and key parity of the locales |
| REQ-TEST-003 / 006 | `Makefile` `test-coverage-100`, `coverage-check` | 100 % gate |
| REQ-TEST-005 | `Makefile` `test-with-db`, `test-coverage-with-db` | Database suite on a server |
| REQ-CS-001 | `Makefile` `validate-phpdoc` | PHPDoc on every class and public method |
| REQ-CS-008 | `Makefile` `igor` | Igor worker-state audit |
| REQ-REL-003 | `Makefile` `check-open-prs` | No open PRs left at release |
| REQ-GIT-001 | `Makefile` `setup-hooks`, `check-no-cursor-coauthor`, `strip-cursor-coauthor-from-history`; CI job "Git history" | No agent co-author trailers |

When scripted behaviour changes, update the matching anchor comment and this table in the same pull request.

## Contributor workflow

1. **Clarify** the behaviour against [CONTRACT.md](CONTRACT.md) and the baseline spec (add or adjust `US-*`, `FR-*`,
   `SC-*` first).
2. **Implement with tests**: a failing test first where practical; keep the 100 % gate, PHPStan, Igor and the worker
   test green.
3. **Anchor scripts**: tooling changes keep or add their `REQ-*` comment.
4. **Ship integrator docs**: USAGE / CONFIGURATION / SECURITY / CHANGELOG / UPGRADING whenever behaviour or
   configuration changes; refresh `specs/001-baseline/code-inventory.md` when `src/` files change.

## Relationship to Engram

[ENGRAM.md](ENGRAM.md) covers the organisation-wide plane (Engram MCP memory, `.cursor/mcp.json`, checklist hygiene).
This document covers the product plane (behaviour and traceability). Neither replaces the other; Engram points here
for local `REQ-*` traceability.

## GitHub Spec Kit

The repository is initialised for GitHub Spec Kit (`.specify/`, Cursor Agent skills in `.cursor/skills/speckit-*`).
The full manual (install, initialise, navigate `.specify/` vs `specs/`, skills) is [SPEC-KIT.md](SPEC-KIT.md). The
baseline lives in [`specs/001-baseline/`](../specs/001-baseline/): `spec.md` (scenarios, requirements, success
criteria) and `code-inventory.md` (every file of `src/` mapped to `FR-*`). Keep the inventory aligned with `src/` in
every pull request that adds, moves or removes a source file.

## See also

- [GitHub Spec Kit](SPEC-KIT.md)
- [Usage](USAGE.md) · [Configuration](CONFIGURATION.md) · [Installation](INSTALLATION.md)
- [Contributing](CONTRIBUTING.md) · [Release](RELEASE.md)
- [FrankenPHP worker audit](FRANKENPHP-WORKER-AUDIT.md)
