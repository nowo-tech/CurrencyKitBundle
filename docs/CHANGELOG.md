# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-11-25

### Documentation

- USAGE: Messenger transport, retries and failure transport of `SyncReferenceRatesMessage` (REQ-MSG-001): in-handler attempts, unrecoverable source responses, transient errors left to the transport's `retry_strategy` and `failure_transport`, idempotent redelivery.

### Internal

- **Deptrac layer check** (REQ-ARCH-002): `deptrac.yaml` with a strict ruleset (Domain / Application framework-free
  apart from `brick/math` behind `Domain\Money\Decimal`, no skipped violations, uncovered dependencies and unassigned
  classes fail), `composer deptrac`, `make deptrac`, part of `make release-check` and of the PHPStan CI job.
- Layer fix, behaviour unchanged: the schema-check command takes the new `Application\SchemaCheckerInterface`, which
  `Infrastructure\Doctrine\SchemaChecker` implements.

## [0.1.1] - 2026-11-22

Messenger handler registered with #[AsMessageHandler] (REQ-MSG-001); transaction-doctrine ^1.8.1; docs and tooling

### Fixed

- `SyncReferenceRatesHandler` is registered with `#[AsMessageHandler]` (autoconfigured, REQ-MSG-001) instead of a hand-written `messenger.message_handler` tag; behaviour is unchanged and hosts without `symfony/messenger` still skip the service. A container test asserts the handler tag.

### Changed

- Requires `nowo-tech/transaction-doctrine` ^1.8.1 (fixes GHSA-9wh3-m3m5-jffx and GHSA-65w2-9r4h-848g); the lock updated to v1.8.1.
- Docs and repository tooling only (no PHP behaviour change): Cursor rule pack, Engram entry and `.cursorignore`, GitHub Spec Kit scaffold with the `specs/001-baseline` spec and a 159-file code inventory, `docs/ENGRAM.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md` and `docs/SPEC-KIT.md`, Cursor Agent settings in CONTRIBUTING, tables of contents for CONTRACT and SECURITY, the Flex recipe in INSTALLATION, the canonical README order ("Found this useful?" line, FrankenPHP banner, Code of Conduct link) and a `strip-cursor-coauthor-from-history` Makefile target.

## [0.1.0] - 2026-11-20

### Added

- 0.1.0 core (contract S1, [docs/CONTRACT.md](CONTRACT.md); owner answers QC-1…15, Beacon answers QB-1…8).
- Domain: decimal-string `Money` (≤ 18 integer + 12 fraction digits) on `brick/math`, `CurrencyCode` with the bundled
  ISO 4217 table (current list of 2026-09-17 and historic codes withdrawn since 1999; precious metals, SDR and test
  codes and the units of account XEU, XFO, XFU, XRE, XAD refused), `RateDate`, `ConvertedAmount` (rounded and exact amount, rate, dates, source, stale flag, manual
  rate id, legs), rounding modes, events and coded exceptions (`CURRENCY_001`…`013`, `ACCESS_002`).
- Storage: five bundle tables (`<prefix>currency_rate_publication`, `currency_rate`, `currency_rate_change`,
  `currency_manual_rate`, `currency_sync_lock`) mapped read-only (XML or attributes) on the configured manager and
  written through DBAL; binary NO PAD identifier columns on MySQL / MariaDB; `nowo:currency-kit:schema-check`.
- Sources: ECB daily / 90-day / full-history XML (streaming, hardened parser), Frankfurter-compatible JSON API
  (off by default), manual-rate source; ECB XML and CSV import for air-gapped installs; every call through
  OutboundUrlGuardBundle with no redirects, timeouts, size caps and an identifying User-Agent.
- Sync, backfill and import with a database lease, one write unit per day, plausibility gate, change log
  (replace / keep), events and audit lines; Scheduler bridge (Mon–Fri 16:15, 17:00, 18:30 and 08:00 Europe/Berlin,
  plus one run on a cold start while no rate exists) and a Messenger handler.
- Converter: legs through EUR, one-step cross amounts (`amount × to ÷ from`, rounded once at scale 12), lookback over
  weekends and TARGET holidays, future / before-history / unknown refusals, "now" through outages up to
  `max_staleness_days` then `fail` or `flag`, manual rates winning on covered dates, per-request memo and an
  optional shared PSR-6 cache.
- Manual rates: create, edit (versioned), end (never deleted), overlap (serialised on a guard row) and deviation
  gates, audit; without `manual.allow_past` an edit or an end never changes a past date.
- Console: `rates:sync`, `rates:backfill`, `rates:import`, `rates:show` (`--health`, `--verify`), `rates:changes`
  (`--apply`), `rates:manual:{set,end,list}`, `rates:prune`, `actor:forget`, `convert`, `schema-check`.
- Twig filter `reference_money`, functions `reference_money_parts` and `reference_rate_note` (10 locales).
- House access pattern: `CurrencyKitAccessCheckerInterface` with role-based and allow-all (demo) checkers,
  `CurrencyKitGuard`, `CURRENCY_ACCESS_CHECK`, `MANUAL_RATE_ACCESS_CHECK` and `SYNC_ACCESS_CHECK` events.
- Tooling: CI matrix (PHP 8.2–8.5 × Symfony 7.4 / 8.1), real-database job (SQLite, MySQL 8.4, MariaDB 11.4,
  PostgreSQL 16), PHPStan max with the FrankenPHP rulesets, Rector, Igor, PHP-CS-Fixer, 100 % coverage gate.
