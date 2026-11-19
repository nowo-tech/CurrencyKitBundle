# Usage

## Table of contents

- [Convert an amount](#convert-an-amount)
- [Which date is used](#which-date-is-used)
- [Errors](#errors)
- [Twig](#twig)
- [Keeping rates current](#keeping-rates-current)
- [Manual rates](#manual-rates)
- [Air-gapped installs](#air-gapped-installs)
- [A custom source](#a-custom-source)
- [Access checks and events](#access-checks-and-events)
- [Console reference](#console-reference)

## Convert an amount

```php
use Nowo\CurrencyKitBundle\Application\CurrencyConverterInterface;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConversionOptions;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Money\Money;
use Nowo\CurrencyKitBundle\Domain\Money\RoundingMode;

$eur = $converter->convert('12.30', 'USD', 'EUR');                       // today in the source calendar
$eur = $converter->convert(Money::of('12.30', CurrencyCode::of('USD')), null, 'EUR', $invoice->issuedAt);
$eur = $converter->convert('0.000003', 'USD', 'EUR', $usageAt, new ConversionOptions(scale: 12));
$eur = $converter->convert('10', 'USD', 'JPY', null, new ConversionOptions(roundingMode: RoundingMode::HalfEven));
$rate = $converter->rate('USD', 'EUR');                                   // ResolvedRate, no amount
$all  = $converter->convertMany($amounts, 'EUR', $periodEnd);             // one lookup per currency
```

`ConvertedAmount`:

| Property | Meaning |
| --- | --- |
| `amount` | `Money` rounded to the target's ISO minor units (or `scale`), `HALF_UP` by default |
| `exactAmount` | scale 12, `HALF_EVEN`, never rounded to the currency: sum this for caps and totals |
| `rate` | effective rate original → target at scale 12 (cross through EUR) |
| `rateDate` / `requestedDate` | the publication date used (the older leg's) and the date asked for |
| `source` / `sourceLabel` | `ecb` (`manual` when an override applied to a leg, `identity` for the same currency) and `ECB` |
| `stale` | true only with `on_stale: flag` or `allowStale` and data older than `max_staleness_days` |
| `manualRateId` | the manual rate used on any leg |
| `legs` | 0, 1 or 2 EUR → currency legs, each with its own date and source |
| `isReference` | always true |

Amounts are **decimal strings** (`^-?(0|[1-9]\d{0,17})(\.\d{1,12})?$`); a float is a `TypeError`. `Money::ofMinor()`
and `toMinor()` convert from and to minor units with the ISO exponent (JPY 0, EUR 2, KWD 3). Currency codes are
exact ISO 4217 codes: `usd` is refused; withdrawn codes (CYP, EEK…) convert on the past dates the stored history
covers; precious metals, SDR and test codes are refused.

Store the result next to what it priced (Beacon cost caps keep `rate`, `rateDate` and `source` with the usage row), so
a later change of a published value never rewrites history.

## Which date is used

| Case | Result |
| --- | --- |
| Business day after the publication | That day's rate |
| Business day before ~16:00 Berlin, `$on = null` | The previous publication (today's is not out yet) |
| Saturday, Sunday, TARGET holiday | The last publication within `max_lookback_days` (7), its date reported |
| Future date | `RateNotAvailableException` (`future_date`) |
| Before the stored history | `RateNotAvailableException` (`before_history`): run a backfill |
| Currency not offered on or near that date | `RateNotAvailableException` (`unknown_for_source`) |
| A manual rate covers the date | The manual rate, `source = manual` |
| "Now" with the latest data older than 10 days | `StaleRateException`, or `stale = true` with `on_stale: flag` |

`$on` is an instant converted to the source calendar's date (`2026-11-19 23:30 America/New_York` is 2026-11-20 in
Berlin). Date-only callers pass `new ConversionOptions(onDate: RateDate::of('2026-11-19'))`. The conversion path
never calls the network, never writes and never locks.

## Errors

| Exception | Code | When |
| --- | --- | --- |
| `InvalidCurrencyCodeException` | `CURRENCY_001` | Not an accepted ISO 4217 code |
| `InvalidAmountException` | `CURRENCY_002` | Amount grammar |
| `RateNotAvailableException` | `CURRENCY_003` | See the table above (`reason`) |
| `StaleRateException` | `CURRENCY_004` | Stale data for "now" |
| `OutboundDisabledException` | `CURRENCY_005` | A sync while `outbound.enabled` is false |
| `RateSourceUnavailableException` | `CURRENCY_006` | Transient: timeout, 5xx, DNS, guard refusal, redirect |
| `RateSourceResponseException` | `CURRENCY_007` | Permanent: 4xx, malformed body, size cap, implausible change |
| `StaleManualRateException` | `CURRENCY_008` | A manual rate edited on an old version |
| `ManualRateOverlapException` | `CURRENCY_009` | Two manual rates for one currency on one date |
| `ManualRateNotFoundException` | `CURRENCY_010` | Unknown manual rate |
| `ManualRateRefusedException` | `CURRENCY_011` | Past window, deviation without force, bad window or reason |
| `InvalidValueException` | `CURRENCY_012` | A value object refused its input |
| `SyncInterruptedException` | `CURRENCY_013` | A run failed after storing some days (they stay stored) |
| `AccessDeniedException` | `ACCESS_002` | The guard refused an admin operation |

All extend `CurrencyKitException` (`errorCode()`, `httpStatus()`); messages are static English strings.

## Twig

```twig
{{ cost|reference_money('EUR', usage.at) }}              {# ≈ €12.30 (reference rate ECB 2026-11-19) #}
{{ '0.25'|reference_money('EUR', '2026-11-19', 'USD', 4) }}  {# a decimal string needs its currency #}
{% set p = reference_money_parts(cost, 'EUR') %}<abbr title="{{ p.rate }} {{ p.rate_date }}">{{ p.formatted }}</abbr>
<p>{{ reference_rate_note() }}</p>
```

Plain text, autoescaped; the request locale formats the amount (10 locales: en, es, ca, eu, gl, fr, pt, it, de, nl).
Missing rate: `twig.on_missing_rate: note` prints `(no reference rate)` and logs a warning; `original` prints
nothing; `exception` rethrows. A float is a `TypeError` in debug and the note otherwise. Needs `ext-intl`.

## Keeping rates current

With Symfony Scheduler (recommended on a worker):

```yaml
nowo_currency_kit:
    scheduler: { enabled: true }
```

Run `bin/console messenger:consume scheduler_nowo_currency_kit`. The schedule sends `SyncReferenceRatesMessage`
Monday to Friday at 16:15, 17:00 and 18:30 Europe/Berlin, at 08:00 (catch-up after downtime), and once right after
the scheduler starts while no rate is stored (then every 30 minutes until the first sync succeeds).

Without Scheduler, cron (times in Europe/Berlin):

```cron
CRON_TZ=Europe/Berlin
15 16 * * 1-5  php bin/console nowo:currency-kit:rates:sync
0 17 * * 1-5   php bin/console nowo:currency-kit:rates:sync
30 18 * * 1-5  php bin/console nowo:currency-kit:rates:sync
0 8 * * 1-5    php bin/console nowo:currency-kit:rates:sync
```

A run before the day's publication reports `not_yet_published` and exits 0. Exit codes: 0 stored / unchanged / not yet
published / skipped (another run holds the lease), 1 transient failure or final lock conflict, 2 permanent source or
validation error, 3 outbound disabled, 4 access denied. `rates:show --health` exits 1 when the latest publication is
older than `max_staleness_days` (for health checks).

Older days: `bin/console nowo:currency-kit:rates:backfill --from=2020-01-01 --actor=ops` (full-history file;
resumable; never automatic).

## Manual rates

```bash
bin/console nowo:currency-kit:rates:manual:set --currency=ARS --rate=1450.5 --from=2026-11-20 --reason="Board rate" --actor=ops
bin/console nowo:currency-kit:rates:manual:set --id=<id> --expected-version=1 --currency=ARS --rate=1460 --from=2026-11-20 --reason="Revised" --actor=ops
bin/console nowo:currency-kit:rates:manual:end <id> --last-day=2026-12-31 --expected-version=2 --actor=ops
bin/console nowo:currency-kit:rates:manual:list --status=active
```

A manual rate is EUR → currency over `[from, until]` and wins over the source on those dates. One per currency and
date (overlap ⇒ `CURRENCY_009`); edits carry the version that was read (⇒ `CURRENCY_008`); a deviation beyond
`manual.max_deviation_percent` needs `--force` (audited); a window starting in the past needs `manual.allow_past`.
Ending keeps the closed window (conversions recorded with the id stay explainable); ending on the day before it starts
retracts it. Manual rates are never deleted. From PHP: `ManualRateServiceInterface`.

## Air-gapped installs

```yaml
nowo_currency_kit:
    outbound: { enabled: false }
    conversion: { source: manual }      # manual rates only, or keep ecb and import files:
```

```bash
bin/console nowo:currency-kit:rates:import eurofxref-hist.xml --actor=ops
bin/console nowo:currency-kit:rates:import rates.csv --format=csv --actor=ops   # date,currency,rate
```

Imports use the feed parser and limits, are stored with origin `import` and are audited. `rates:sync` exits 3.

## A custom source

```php
use Nowo\CurrencyKitBundle\Domain\Port\RateSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('nowo_currency_kit.rate_source')]
final class TreasuryRates implements RateSourceInterface { /* key(), label(), supports(), fetch(), earliestDate(), timezone() */ }
```

`fetch()` returns EUR-based decimal strings and does no storage; call the network through
`OutboundFetcherInterface` (guarded, capped). Set `conversion.source` to its key.

## Access checks and events

Admin operations (every console command in 0.1; the UI in 0.2) go through `CurrencyKitGuard`:

- area checker `CurrencyKitAccessCheckerInterface` (`canViewRates`, `canManageManualRates`, `canTriggerSync`); the
  default maps `security.*_roles`; a host with permission sets plugs its own with `security.access_checker`;
- `CurrencyKitEvents::CURRENCY_ACCESS_CHECK` (`CurrencyAccessCheckEvent`) for every area decision;
- `CurrencyKitEvents::MANUAL_RATE_ACCESS_CHECK` (view, create, edit, end) and `CurrencyKitEvents::SYNC_ACCESS_CHECK`
  (sync, backfill, import, apply change, prune) per record.

`grant()` / `deny()` override the default; a deny sticks. On the web, no user and no matching role means denied. In
the console the operator's default is granted (shell access already reaches the database); a listener may deny.
Writing commands need `--actor` when no security user exists.

Domain events, dispatched after the commit: `ReferenceRatesPublished`, `ReferenceRateChanged`,
`ReferenceRateSyncFailed`, `ReferenceRatesStale` (at most hourly per source with a shared cache), `ManualRateCreated`,
`ManualRateUpdated`, `ManualRateEnded`.

## Console reference

| Command | Purpose |
| --- | --- |
| `nowo:currency-kit:rates:sync [--source] [--feed=daily\|90d] [--accept-implausible=CODE] [--actor]` | Sync |
| `nowo:currency-kit:rates:backfill --from [--to] [--source] --actor` | Older days |
| `nowo:currency-kit:rates:import <file> [--source] [--format=ecb-xml\|csv] --actor` | Local file |
| `nowo:currency-kit:rates:show [--date] [--currency] [--health] [--verify]` | Table, health, hash check |
| `nowo:currency-kit:rates:changes [--from] [--to] [--apply=<id> --actor]` | Change log, apply a kept change |
| `nowo:currency-kit:rates:manual:{set,end,list}` | Manual rates |
| `nowo:currency-kit:rates:prune --before --actor` | Delete old days (never changes or manual rates) |
| `nowo:currency-kit:actor:forget <ref> --actor` | Remove an actor reference |
| `nowo:currency-kit:convert <amount> <from> <to> [--date]` | Debug helper |
| `nowo:currency-kit:schema-check [--ddl]` | Live tables vs mapping, or the reference DDL |
