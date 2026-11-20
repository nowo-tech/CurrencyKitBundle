# Feature Specification: CurrencyKitBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-20
**Status**: Baseline of release 0.1.0 (core, console, Scheduler bridge, Twig filter; no web UI yet)
**Input**: Design contract [`docs/CONTRACT.md`](../../docs/CONTRACT.md) and the 0.1.0 source tree.

Related: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md),
[`docs/SECURITY.md`](../../docs/SECURITY.md), [`code-inventory.md`](code-inventory.md).

## Summary

CurrencyKitBundle converts amounts between currencies at **reference rates**. Rates published by the European Central
Bank are stored by date in the host's database (through its DBAL connection); amounts are decimal strings; cross rates
go through the euro; every result carries the rate, its publication date, its source and a stale flag, and says it is a
reference rate (`isReference = true`). The configuration root is `nowo_currency_kit` (strict: unknown keys fail the
container build). 0.1.0 ships the converter, the sources, the sync, the manual rates, the console, the Scheduler
bridge, the Messenger handler and the Twig filter. The admin UI is announced for 0.2.0 and `ui.enabled: true` is
refused in 0.1.

## User scenarios

### US-01 — Convert an amount (core)

- **Given** stored rates, **when** `CurrencyConverterInterface::convert('100', 'USD', 'EUR', $date)` runs, **then** the
  result holds the rounded amount (target minor units, `HALF_UP` by default), the exact amount at scale 12, the
  effective rate, the publication date used, the source and the stale flag.
- **Given** a weekend or TARGET holiday, **when** a conversion asks for that date, **then** the last publication within
  `max_lookback_days` is used and its date is reported; a future date, a date before the stored history or an unknown
  currency is refused with a coded exception.
- **Given** a conversion for "now" and data older than `max_staleness_days`, **when** `on_stale` is `fail`, **then**
  `StaleRateException` is thrown; with `flag` the result carries the stale flag.

### US-02 — Keep the rates current (sync)

- **Given** an empty store, **when** `rates:sync` runs, **then** the last 90 days are loaded (`sync.initial`).
- **Given** a published day already stored with a different value, **when** a sync sees it, **then** the value is
  replaced only with a change-log row, a warning, an audit line and an event (`on_changed_value: replace`), or kept
  until an operator applies it (`keep`); a currency that disappears is never deleted.
- **Given** a move larger than `max_daily_change_percent`, **when** the day is validated, **then** it fails as
  implausible unless an actor accepts it with `--accept-implausible`.
- **Given** two runs at once, **when** both start, **then** one holds the database lease and the other stops.
- **Given** `outbound.enabled: false`, **when** a sync starts, **then** no HTTP and no DNS happen and
  `OutboundDisabledException` is thrown.

### US-03 — Manual rates and air-gapped installs

- **Given** a manual rate (reason and actor required, versioned), **when** a conversion falls in its window, **then** it
  wins over the source and the result names its id.
- **Given** a host without outbound access, **when** an operator runs `rates:import` with an ECB XML or CSV file,
  **then** the days are stored like a sync.

### US-04 — Show a price honestly (Twig)

- **Given** `{{ cost|reference_money('EUR', usage.at) }}`, **when** it renders, **then** the output is plain text such
  as `≈ €12.30 (reference rate ECB 2026-11-19)`, autoescaped, in the request locale; a missing rate follows
  `on_missing_rate`.

### US-05 — Safe under contention and in a worker

- **Given** one kernel kept alive and not reset, **when** it serves many requests, **then** services hold configuration
  only, the request memo is cleared at every boundary and no entity manager is flushed, cleared or reset.
- **Given** two writers on one day, one manual rate or the run lease, **when** they collide, **then** write units retry
  deadlocks and lock timeouts, and a lost race surfaces as `CONFLICT_001` / `CONFLICT_002`.

### US-06 — Guarded administration

- **Given** an admin operation (every console command in 0.1), **when** it runs, **then** `CurrencyKitGuard` asks the
  area checker and the `*_ACCESS_CHECK` events; on the web no user and no role means denied; the console policy is
  documented in [`docs/SECURITY.md`](../../docs/SECURITY.md).

## Functional requirements

### Bundle and configuration

- **FR-BUNDLE-001**: The bundle registers in a standard Symfony host with no configuration; the services are built by
  explicit definitions and the ports compiler pass.
- **FR-BUNDLE-002**: The `nowo_currency_kit` tree is strict; requirement checks run at build time (enabled source for
  `conversion.source`, https feed URLs, Frankfurter `base_uri`, `allow_all` needs `allow_unauthenticated`).

### Money and currencies

- **FR-MONEY-001**: Money is a decimal string (up to 18 integer and 12 fraction digits) on `brick/math`; floats are
  refused; rounding modes are explicit.
- **FR-MONEY-002**: Currency codes come from the bundled ISO 4217 table; unaccepted codes (precious metals, SDR, test
  codes, units of account) are refused with `CURRENCY_001`.

### Rates and reading

- **FR-RATE-001**: Rates are stored EUR-based per source, date and currency, with a publication row per day and an
  append-only change log, behind the `RateStoreInterface` port.
- **FR-RATE-002**: `ReferenceRateReaderInterface` and the `rates:show`, `rates:changes` commands read the latest
  publication, a day's rates, a bounded history and the change log.

### Conversion

- **FR-CONVERT-001**: Conversion builds legs through the euro, computes one cross amount rounded once at scale 12 and
  then rounds to the target's minor units; the result is flagged as a reference rate.
- **FR-CONVERT-002**: Lookback, history, staleness and unknown-currency rules of US-01; manual rates win on the dates
  they cover; the conversion path never calls the network.

### Sources and outbound

- **FR-SOURCE-001**: Sources sit behind `RateSourceInterface`: ECB daily, 90-day and full-history XML (streaming,
  hardened parser), a Frankfurter-compatible JSON API (off by default) and the manual source.
- **FR-SOURCE-002**: Every outbound call goes through the guarded fetcher (OutboundUrlGuardBundle): pinned public
  address, no redirects, timeouts, size caps, identifying User-Agent; `outbound.enabled: false` disables all of it.
  Local files are read through `RateFileReaderInterface`.

### Sync and maintenance

- **FR-SYNC-001**: Sync, backfill and import fetch outside any lock, then write one unit per publication day under a
  database lease; transient and permanent source failures are told apart.
- **FR-SYNC-002**: The Scheduler bridge (optional) runs Monday to Friday on the configured Europe/Berlin times plus one
  run on a cold start; a Messenger handler runs the same synchroniser and is idempotent per day; prune deletes old days
  in batches and never touches change rows or manual rates.

### Manual rates

- **FR-MANUAL-001**: Manual rates are created, updated (optimistic version) and ended with a reason and an actor; an
  overlap on one currency and date is refused (`CURRENCY_009`).
- **FR-MANUAL-002**: A manual rate far from the source's latest value needs `--force`; windows in the past need
  `manual.allow_past`.

### Storage

- **FR-STORAGE-001**: Five bundle tables (publication, rate, change, manual rate, sync lock) with a configurable prefix,
  mapped read-only (XML or attributes) and written through DBAL; binary identifier columns on MySQL / MariaDB.
- **FR-STORAGE-002**: `schema-check` compares the live tables with the mapping and prints the reference DDL; the bundle
  ships no migrations.

### Concurrency and worker mode

- **FR-CONCURRENCY-001**: Every write is a transaction-doctrine write unit on the connection (never an entity
  manager); the retry classes are 1213, 1205, 1020, 40001, 40P01 and 55P03; a committed attempt is never replayed.
- **FR-WORKER-001**: The kernel may stay alive without reset: services are readonly and hold configuration; the memo
  is bounded and cleared at every request, console and worker boundary.

### Access and audit

- **FR-ACCESS-001**: Admin operations pass the area checker port (role-based default, deny by default).
- **FR-ACCESS-002**: `CurrencyAccessCheckEvent`, `ManualRateAccessCheckEvent` and `SyncAccessCheckEvent` let a host
  grant or deny per decision; a deny sticks.
- **FR-ACCESS-003**: `actor:forget` removes an actor reference from stored rows.
- **FR-AUDIT-001**: Writes produce an audit entry through `CurrencyKitAuditSinkInterface` (default: one PSR log line).

### Events, errors, console, Twig, translations

- **FR-EVENT-001**: Domain events are dispatched after commit and carry value objects and ids only.
- **FR-ERROR-001**: Failures are coded exceptions (`CURRENCY_001` … `CURRENCY_013`, `ACCESS_002`) extending
  `CurrencyKitException`; messages are static English strings.
- **FR-CLI-001**: The console commands of [`docs/USAGE.md`](../../docs/USAGE.md#console-reference) share one support
  class for actor and output handling; `schema-check` is documented in INSTALLATION.
- **FR-TWIG-001**: The `reference_money` filter, `reference_money_parts` and `reference_rate_note` are plain text,
  autoescaped, and registered only when Twig and the translator exist.
- **FR-I18N-001**: Ten translation catalogues (ca, de, en, es, eu, fr, gl, it, nl, pt) with the same keys.

## Success criteria

- **SC-001**: Every file under `src/` appears in [`code-inventory.md`](code-inventory.md): **159 / 159**.
- **SC-002**: PHPUnit (Unit, Architecture, Integration with SQLite; MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 in CI)
  passes and `src/` coverage is 100 %.
- **SC-003**: PHPStan level max with the `phpstan-frankenphp` rulesets, Igor, PHP-CS-Fixer and Rector are green.
- **SC-004**: `LayerDependencyTest` proves the Domain layer has no framework dependency.

## Non-goals

- Payment, settlement, trading, treasury or accounting rates; bid / ask, spreads or intraday rates.
- Cryptocurrencies, commodity, fund and test codes.
- A money library replacement, and any network call on the conversion path.
- A web UI and a public JSON API in 0.1 (see [CONTRACT §22](../../docs/CONTRACT.md)).

## Validation commands

```bash
make test-coverage-100   # PHPUnit + 100 % of src/
make test-with-db        # database suite on a server
make phpstan igor        # static analysis and worker-state audit
make qa                  # cs-check + test
make release-check       # the whole pre-release chain
```
