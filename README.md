# Currency Kit Bundle

[![CI](https://github.com/nowo-tech/CurrencyKitBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/CurrencyKitBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/currency-kit-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/currency-kit-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> **Reference rates only.** The rates this bundle stores and applies are reference rates published for information
> purposes. They are not payment, settlement, trading or accounting rates, and every result says so
> (`isReference = true`). Do not use them to settle money.

**Currency conversion at reference rates for Symfony.** The euro reference rates of the European Central Bank are
stored by date in your database; amounts are decimal strings; cross rates go through the euro; every result carries
the rate, its publication date and its source so a page or a log can show it honestly.

**Standard Symfony by default, DDD / hexagonal opt-in:** zero configuration in a standard project; the public API is
interfaces and value objects with no Symfony, Doctrine or HTTP types; the bundle's tables are mapped read-only and
written through DBAL, so the bundle never flushes, clears or resets your entity manager.

> 📋 **Compatible with Symfony 7.4, 8.0 and 8.1** · PHP 8.2+ (Symfony 8.x requires PHP 8.4+)

This bundle is **FrankenPHP worker mode friendly** (kernel kept alive and not reset): services hold configuration
only; the request memo is cleared at every boundary. See [FRANKENPHP-WORKER-AUDIT.md](docs/FRANKENPHP-WORKER-AUDIT.md).

## Features

- `CurrencyConverterInterface::convert('0.000003', 'USD', 'EUR', $usageAt)` → `ConvertedAmount`: the rounded amount
  (target minor units, `HALF_UP` by default), the exact amount at scale 12 (sub-cent prices and caps), the effective
  rate, the publication date used, the source and a stale flag.
- Weekends and TARGET holidays use the last publication within 7 days and report its date; future dates and dates
  before the stored history are refused; stale data for "now" fails after 10 days (or is flagged).
- Sources behind a port: the ECB daily, 90-day and full-history XML files (plus a file import for air-gapped
  installs), a Frankfurter-compatible JSON API (a self-hosted mirror), and manual rates.
- Every outbound call goes through OutboundUrlGuardBundle: pinned public address, no redirects, timeout, size cap,
  identifying User-Agent; `outbound.enabled: false` turns every call off.
- Idempotent sync (console, or the Symfony Scheduler bridge Mon–Fri from 16:15 Europe/Berlin, plus one run on a cold
  start): fetch outside any lock, then one write unit per publication day; a changed published value is replaced only
  with a change-log row, a warning, an audit line and an event; a removed currency is never deleted.
- Manual rates (versioned, reason required, audited) win over the source on the dates they cover.
- Twig: `{{ cost|reference_money('EUR', usage.at) }}` prints `≈ €12.30 (reference rate ECB 2026-11-19)`, plain text,
  autoescaped, in 10 locales.
- House access pattern for admin operations: area checker port with a role-based default, `*_ACCESS_CHECK` events,
  deny by default.
- REQ-RUNTIME-003: transaction-doctrine write units, 409 + `Retry-After` semantics (`CONFLICT_001` / `CONFLICT_002`),
  real-database CI on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16.

0.1.0 ships the core, the console, the Scheduler bridge and the Twig filter. The UiKit admin UI arrives in 0.2.0.

## Installation

```bash
composer require nowo-tech/currency-kit-bundle
```

Create the tables (reference DDL in [docs/INSTALLATION.md](docs/INSTALLATION.md)), then load the last 90 days:

```bash
bin/console nowo:currency-kit:rates:sync
bin/console nowo:currency-kit:convert 100 USD EUR
```

## Usage

```php
use Nowo\CurrencyKitBundle\Application\CurrencyConverterInterface;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConversionOptions;

$result = $converter->convert('0.000003', 'USD', 'EUR', $usage->at, new ConversionOptions(scale: 12));
$result->exactAmount;   // '0.000002729258' (sum these for caps)
$result->rate;          // '0.909752547307'
$result->rateDate;      // 2026-11-19 (store it next to the usage row)
$result->source;        // 'ecb' ('manual' when an override applied)
```

More in [docs/USAGE.md](docs/USAGE.md).

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Data sources](docs/SOURCES.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Concurrency](docs/CONCURRENCY.md)
- [Security](docs/SECURITY.md)
- [FrankenPHP worker audit](docs/FRANKENPHP-WORKER-AUDIT.md)
- [Contract](docs/CONTRACT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Release](docs/RELEASE.md)
- [GitHub Actions CI](docs/GITHUB_CI.md)
- [PSR evaluation](docs/PSR.md)

## Requirements

- PHP >= 8.2, < 8.6 with `ext-xmlreader`, `ext-libxml`, `ext-json` (`ext-intl` for the Twig filter)
- MySQL 8.4, MariaDB 11.4 or PostgreSQL 16 (SQLite for development and tests only: it cannot hold full-precision rates)
- Symfony 7.4, 8.0 or 8.1; Doctrine DBAL 3.8+ / 4, ORM 3, DoctrineBundle 2.8+ / 3
- `nowo-tech/outbound-url-guard-bundle` ^1.5, `nowo-tech/transaction-doctrine` ^1.7, `brick/math` ^1.0
- Optional: `symfony/scheduler` + `dragonmantank/cron-expression` (scheduled sync), `symfony/messenger`,
  `symfony/security-bundle` (role-based checker), `symfony/twig-bundle`

## Tests and coverage

- Unit, architecture and database suites; the database suite runs on SQLite and, in CI, on MySQL 8.4, MariaDB 11.4
  and PostgreSQL 16 (two-connection lock tests included). Sources are tested with recorded ECB XML and
  Frankfurter-compatible JSON files only; no test touches the network.
- Coverage:
  - PHP: 100%
  - TS/JS: N/A
  - Python: N/A

## License

MIT. See [LICENSE](LICENSE).

Rates published by the European Central Bank: "Source: ECB". The ECB publishes its reference rates for information
purposes only.
