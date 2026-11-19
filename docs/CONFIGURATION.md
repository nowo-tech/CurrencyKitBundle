# Configuration

Every key with its default. The tree is strict: unknown keys fail the container build.

```yaml
nowo_currency_kit:
    doctrine:
        entity_manager: null          # manager whose CONNECTION the bundle uses (null: the default one); never flushed
        table_prefix: nowo_           # ^[a-z][a-z0-9_]{0,15}$ or empty
        mapping: xml                  # xml | attribute (read-only entities for schema tools and host queries)
    sources:
        ecb:
            enabled: true
            label: ECB
            daily_url: 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml'
            last_90_days_url: 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml'
            history_url: 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml'
        frankfurter:                  # a Frankfurter-compatible JSON API (self-hosted mirror); off by default
            enabled: false
            key: mirror               # storage key; never ecb, manual or identity
            label: ECB                # what users see (it serves ECB data)
            base_uri: null            # https://rates.internal.example/v1 (http only with allow_private_network)
            timezone: Europe/Berlin
            allow_private_network: false
            rebase: false             # accept a non-EUR base and rebase to EUR
        manual:
            label: Manual             # label of results under conversion.source: manual
    conversion:
        source: ecb                   # ecb | <frankfurter key> | manual
        max_lookback_days: 7          # 4..31: weekends, TARGET holidays (Easter: Thursday → Tuesday)
        max_staleness_days: 10        # 1..366, for "now" only
        on_stale: fail                # fail | flag (accepts %env()%)
        rounding_mode: half_up        # half_up | half_even | half_down | down | up | ceiling | floor
    sync:
        initial: last_90_days         # first sync loads the 90-day file; none = only backfill / import
        times: ['16:15', '17:00', '18:30']   # Europe/Berlin, Monday to Friday (Scheduler bridge)
        catch_up_time: '08:00'
        on_changed_value: replace     # replace | keep (keep: apply later with rates:changes --apply)
        max_daily_change_percent: 50  # 1..1000: a bigger move fails the day (implausible_change)
        lock:
            store: database           # 0.1 ships the database lease
            ttl_seconds: 600          # 60..3600, renewed between days
    backfill:
        max_days: 10000               # longest backfill range (≈ the whole ECB history)
        chunk_days: 366               # range size for an API source
    outbound:
        enabled: true                 # false: no HTTP and no DNS at all (accepts %env(bool:…)%; unreadable = false)
        timeout: 10                   # seconds idle, 1..60
        max_duration: 30              # seconds, 1..120; the full history uses max(this, 120)
        retries: 2                    # 0..5, console and Messenger only
        contact_url: 'https://github.com/nowo-tech/CurrencyKitBundle'   # in the User-Agent; https only, no e-mail
        max_bytes: { daily: 262144, last_90_days: 4194304, history: 67108864, api: 4194304 }
    manual:
        max_deviation_percent: 50     # a manual rate further from the source's latest value needs --force
        allow_past: false             # manual windows starting in the past
    scheduler:
        enabled: false                # registers the schedule "nowo_currency_kit" (needs symfony/scheduler)
        transport: null               # documentation hint for routing SyncReferenceRatesMessage
    cache:
        pool: null                    # optional PSR-6 pool shared by workers (versioned keys)
        ttl: 300                      # 1..300 seconds
    twig:
        enabled: true                 # when Twig and the translator are present; needs ext-intl
        on_missing_rate: note         # note | original | exception
        date_format: iso              # iso (Y-m-d) | locale (IntlDateFormatter MEDIUM)
    ui:
        enabled: false                # the admin UI arrives in 0.2.0 (true fails the build in 0.1)
        path_prefix: /admin/currency  # container parameter nowo_currency_kit.ui_path_prefix
    security:                         # house access pattern (REQ-UI-002)
        access_roles: [ROLE_ADMIN]
        access_checker: null          # service id of a CurrencyKitAccessCheckerInterface, or allow_all (demos)
        allow_unauthenticated: false  # DEV / DEMO only; required by allow_all
        dashboard_roles: []           # view rates; empty = access_roles
        manage_roles: [ROLE_ADMIN]    # manual rates, sync, prune
    audit:
        sink: null                    # service id of a CurrencyKitAuditSinkInterface (default: one PSR log line)
```

## Rules checked at build time

- `conversion.source` names an enabled source or `manual`.
- `sources.frankfurter.enabled` needs `base_uri` (https; http only with `allow_private_network`).
- `security.access_checker: allow_all` needs `allow_unauthenticated: true`.
- `twig.enabled` with Twig present needs `ext-intl`; `scheduler.enabled` needs `symfony/scheduler`.
- `ui.enabled: true` is refused in 0.1.

## Values read when the container starts

`outbound.enabled` and `conversion.on_stale` accept `%env()%` placeholders (`%env(bool:CURRENCY_OUTBOUND)%`); they
are resolved when the services are built (once per worker). An unreadable `outbound.enabled` counts as false (fail
closed); an unknown `on_stale` fails. Registration as runtime settings of `nowo-tech/runtime-setting-contract`
(database values changed live) is planned for 0.2.

## Services

| Id / interface | Purpose |
| --- | --- |
| `Nowo\CurrencyKitBundle\Application\CurrencyConverterInterface` | Conversions |
| `Nowo\CurrencyKitBundle\Application\ReferenceRateReaderInterface` | Stored rates, history, change log |
| `Nowo\CurrencyKitBundle\Application\RateSynchronizerInterface` | Sync, backfill, import |
| `Nowo\CurrencyKitBundle\Application\ManualRateServiceInterface` | Manual rates |
| `Nowo\CurrencyKitBundle\Domain\Port\CurrencyCatalogueInterface` | ISO 4217 table (decorate it to add a code) |
| `Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitGuard` | Access decisions of admin operations |
| tag `nowo_currency_kit.rate_source` | Extra sources (`RateSourceInterface`) |
