# Currency kit bundle (CurrencyKitBundle). Contract v0.1

Status 2026-11-19: **v0.1, design only, awaiting the owner (QC, §24) and Symfony Beacon (QB, §25).** No code, no
PR, no tag, no Packagist. The repository `nowo-tech/CurrencyKitBundle` (public, MIT) exists and stays empty until the
answers are recorded (§26). The owner registers the package on Packagist before the first tag.

## Summary (10 lines)

1. Converts amounts between currencies at **reference rates** (ECB euro reference rates by default); it is never a payment, settlement or accounting rate source, and every result says so (`isReference = true`).
2. Public API: `CurrencyConverterInterface::convert(Money|string amount + currency, target, ?DateTimeInterface $on)` → `ConvertedAmount` (rounded and exact amount, rate, rate date, source, stale flag, override id).
3. Money is a **decimal string** (up to 18 integer and 12 fraction digits) with an ISO 4217 code from a bundled table; maths with `brick/math`; results rounded to the target's ISO minor units, `HALF_UP` by default; exact amount kept.
4. Rates are stored EUR-based by date in one bundle table (`unique source + rate_date + currency`), plus a publication row per day and an append-only change log; cross rates go through EUR.
5. A date with no publication (weekend, TARGET holiday) uses the last published day within 7 days and reports that date; future dates and dates before the stored history are refused; stale data beyond 10 days fails by default.
6. Sources are a port: ECB XML (daily, 90-day, full history; file import for air-gapped installs), Frankfurter-compatible JSON API, and manual rates; every call goes through OutboundUrlGuard (no redirects, timeout, size cap, identifying User-Agent); `outbound.enabled: false` turns every call off.
7. Sync (console + optional Scheduler, Mon–Fri from 16:15 Europe/Berlin with retries) is idempotent: fetch outside any lock, then one write unit per publication day; a changed published value is replaced only with a change-log row, a warning and an event.
8. Manual rates (admin, versioned, reason required, audited) win over the source for the dates they cover; the conversion path never calls the network.
9. Optional UiKit UI (rates page, manual rates, "sync now") behind the house access pattern, deny by default; Twig filter `|reference_money('EUR', date)` prints `≈ 12,30 € (reference rate ECB 2026-11-19)`, plain text, autoescaped.
10. House bars from the first PR: worker mode with kernel reset off, transaction-doctrine write units, REQ-RUNTIME-003 (409 + Retry-After, `CONFLICT_001/002`), standard + DDD hosts, 7 locales, recorded fixtures only, PHPStan max, 100 % coverage; 0.1.0 = core + console + Twig, 0.2.0 = UI.

## Sources read for this contract

| Source | Version read | What was read |
| --- | --- | --- |
| Beacon request (relayed by the owner, 2026-11-19) | brief | First user (AI cost caps: USD list prices, EUR connections), later billing and CRM; ECB feeds; pluggable source; storage, sync, API, outbound, overrides, UI, house rules |
| WorkingTimeBundle | `docs/CONTRACT.md` v0.1 (answered 2026-11-18), `docs/ARCHITECTURE.md` | House shape of a new-bundle contract: findings, package, layout, VOs, tables, ports, config, concurrency, worker, both host kinds, slices, tests, questions |
| OutboundUrlGuardBundle | local clone, latest tag `v1.5.0` | `docs/USAGE.md`, `docs/CONFIGURATION.md`: `RuntimeOutboundUrlGuard::httpClientOptions()` (pins the resolved IP, throws `UnsafeOutboundUrlException`), `max_redirects = 0` required, `allowPrivate` per call, `dns_timeout`, worker-safe |
| CrmKit 0.3.0 design | `.uic-work/crmkit-1118/DESIGN-0.3.0-API-OPERATIONS.md` | Question format (number, question, recommendation, if not), error-code shape `^[A-Z][A-Z0-9]*_[0-9]{3}$`, `X-Conflict-Code`, `X-Error-Code` |
| House rules | `BUNDLES_FIX_RULES.md`, `BUNDLES_FULL_SPECS_DETAILS.md` | REQ-ARCH-001 (rules 1–8, rule 3 strict), REQ-RUNTIME-002, REQ-RUNTIME-003 (rules 1–9, CommitLedger ≥ 1.6), REQ-UI-002 (`security.*` keys), identifier columns NO PAD (LQ), runtime settings env / DB, public API paths, demos UiKit + FormKit |
| House access pattern | owner 2026-11-19 (CrmKit) | `security.access_roles` / `access_checker` / `allow_unauthenticated` / `*_roles`, `{Feature}AccessCheckerInterface` with Configurable / AllowAll defaults, `*_ACCESS_CHECK` events (grant / deny / default), `{Feature}Guard` (TaskBoard `src/Security`, `Event/*AccessCheck*`) |
| Dependency tags (local) | 2026-11-19 | TransactionDoctrine `v1.7.1`, UiKit `v2.58.1`, FormKit `v2.23.0`, OutboundUrlGuard `v1.5.0` |

## 0. Findings that shaped the contract

| # | Finding | Effect |
|---|---|---|
| F1 | ECB reference rates are published "for information purposes only", once per TARGET business day around 16:00 CET, EUR-based, about 30 currencies, 4–6 significant digits; currencies come and go (historical codes in the full-history feed; some suspended in recent years) | The bundle is reference-only (§1); storage is EUR-based; currency availability is per day, never assumed from a static list (§9.3) |
| F2 | The full-history XML is several MB and grows; the 90-day file covers any normal catch-up | Streaming `XMLReader` parser with size caps (§13.3); the hist feed is used only by an explicit backfill (§8.4) |
| F3 | Beacon's first use is cost caps on AI calls: per-token list prices are fractions of a cent (`0.000003 USD`). Rounding each conversion to cents gives 0 | `ConvertedAmount` keeps an **exact** amount (scale 12) next to the rounded one; callers choose the scale (§5.3, QB-2) |
| F4 | OutboundUrlGuard pins the resolved IP and does not send the request; a redirect is a second URL the pin does not cover | Every source call sets `max_redirects: 0`; a 3xx is a source error with its own message (§7.2) |
| F5 | The request asks for "a Doctrine entity on the host EM (configurable manager)", while REQ-ARCH-001 rule 6 and REQ-RUNTIME-003 rule 2 forbid flushing or clearing the host EM | Entities are mapped (XML default + attribute twin) on the configured manager for schema tools and host reads, but **the bundle writes and reads through DBAL on that manager's connection** inside transaction-doctrine write units; it never calls `persist()`, `flush()` or `clear()` on any EM (§6.1, QC-3) |
| F6 | REQ-RUNTIME-003 rule 9: no HTTP I/O while holding a lock | Sync = fetch and parse first (no transaction), then a short write unit per day (§8.2) |
| F7 | A conversion that falls back to the network would add seconds to a request path and break air-gapped installs | The conversion path is **storage only**; missing data is an exception, never a live fetch (§9.1, QC-9) |
| F8 | Rates are instance-wide public data; overrides change results for every tenant of the instance | 0.1 has one instance-wide rate set and instance-admin overrides; per-tenant overrides are a later question (QC-8) |

## 1. Purpose and non-goals

**Purpose.** Give Symfony applications one place to say "this amount in that currency, at the reference rate of that
day", with the rate, its date and its source carried along so the UI and logs can show it honestly. First user: Beacon
AI cost caps (provider list prices in USD, connections and caps in EUR). Later: billing previews, CRM quotes and
invoices shown in another currency.

**Non-goals (0.x and 1.0):**

- Not a payment, settlement, FX trading, treasury or accounting rate source. No bid / ask, no spreads, no intraday
  rates, no "today's market rate". Docs, the UI note and the Twig output say "reference rate".
- No legal conversion for invoices (VAT, tax authority rates such as customs or tax rates are a host concern; a host
  may plug such a feed in as a custom source, but the bundle still labels it a reference rate unless the host sets the
  source label).
- No cryptocurrencies, no commodity codes (XAU, XAG…), no fund codes (XDR, XSU…) in 0.1 (ISO 4217 X-codes refused,
  QC-5).
- No money library replacement: the bundle's `Money` is a small VO for its own port; bridges accept other libraries'
  objects (§5.2).
- No live conversion through a remote API on the request path (F7).
- No public JSON API in 0.1 (QC-12).
- Docs describe what the bundle does and which feeds / protocols it speaks; they never compare it with or cite other
  products.

## 2. Package

| Item | Proposal |
|---|---|
| Name | `CurrencyKitBundle`, Composer `nowo-tech/currency-kit-bundle`, GitHub `nowo-tech/CurrencyKitBundle` (public, MIT) |
| Namespace / class | `Nowo\CurrencyKitBundle` / `NowoCurrencyKitBundle`; config root `nowo_currency_kit`; Twig namespace `@NowoCurrencyKit`; translation domain `NowoCurrencyKitBundle` (7 locales: en, es, ca, eu, gl, fr, pt) |
| PHP / Symfony | `>=8.2 <8.6`; `^7.4 \|\| ^8.0`; demos Symfony 7.4 + 8.1 on FrankenPHP worker |
| Runtime deps | `brick/math` (latest minor at build time; QC-2), `doctrine/dbal ^3.8.2 \|\| ^4.0`, `doctrine/orm ^3.x` (mapping only), `doctrine/doctrine-bundle ^2.8 \|\| ^3.0`, `symfony/{framework-bundle,http-kernel,config,dependency-injection,event-dispatcher,clock,console,http-client,uid}`, `psr/clock`, `psr/log`, `psr/cache`, `nowo-tech/transaction-contract ^1.0`, `nowo-tech/transaction-doctrine ^1.7` (write units, `ConcurrencyConflictResponder`, `CommitLedger`, `ResetBetweenAttemptsInterface`), `nowo-tech/outbound-url-guard-bundle ^1.5`, `ext-xmlreader`, `ext-libxml`, `ext-json` |
| Suggest | `ext-intl` (required when `twig.enabled`; locale formatting), `symfony/twig-bundle`, `symfony/scheduler` + `symfony/messenger` (scheduled sync), `symfony/lock` (sync run lock backend `lock_factory`), `symfony/security-bundle` (UI), `nowo-tech/ui-kit-bundle ^2.58` + `nowo-tech/form-kit-bundle ^2.23` (UI), `nowo-tech/audit-kit-bundle` (audit sink), `nowo-tech/http-log-bundle` (outbound call names), `moneyphp/money` / `brick/money` (bridges) |
| Not used | No JS framework, no external FX API by default, no network on the conversion path |
| First tag | 0.1.0 after S1 (§22) and the batched audit; 1.0.0 after `bundle-req-audit` 100 % and SEC-004 |

## 3. Shape

```
                                 Host (Beacon, CRM, billing)
  ports ────────────────────────────────────────────────────────────────────────────────────
  RateSource* (tagged) · RateStore · Clock (PSR-20) · OutboundFetcher (guard) · ActorResolver
  AuditSink · CurrencyKitAccessChecker · CurrencyCatalogue
  ───────────────────────────────────────────────────────────────────────────────────────────
  Presentation (opt-in)                         Bridges (auto when installed, can be switched off)
    UI: rates · manual rates · sync now           AuditKit · HttpLog · MoneyPHP · Brick\Money
    Twig: reference_money, reference_rate         Scheduler (RecurringMessage provider)
  ───────────────────────────────────────────────────────────────────────────────────────────
  Application   CurrencyConverter · RateResolver · RateSynchronizer · ManualRateService
                RateImporter · TargetCalendar
  Domain        Money · CurrencyCode · Rate · RateDate · ConvertedAmount · enums · ports · events · exceptions
  Infra         DBAL store on the configured manager's connection · XML / JSON parsers · write units
  Console       rates:sync · rates:backfill · rates:import · rates:show · rates:manual:{set,end,list} · schema-check
```

The bundle owns the rate data, the parsers, the sync, the conversion rules and the optional UI. The host owns who is
an admin, the actor id, where the UI is mounted, the schedule (or uses the recipe) and which source is active.

## 4. Layout and architecture rules

```
src/Domain/              Money, CurrencyCode, Rate, ConvertedAmount, enums, ports, events, exceptions (plain PHP)
src/Application/         converter, resolver, synchronizer, manual rates, importer, calendar (no Symfony / Doctrine types)
src/Infrastructure/Dbal/          DBAL store, write units, schema listener (collations), reference DDL
src/Infrastructure/Doctrine/      entities (read models for host queries) + XML mapping + attribute twins
src/Infrastructure/Source/        Ecb\{EcbXmlParser,EcbDailySource,Ecb90DaySource,EcbHistorySource}, Frankfurter\*, Manual\*
src/Infrastructure/Symfony/       DI, config tree, commands, Messenger handler, controllers, Twig, security
src/Bridge/{AuditKit,HttpLog,MoneyPhp,BrickMoney,Scheduler}/
src/Resources/{config,views,translations,currency}/   (currency/iso4217.php: generated table, §5.1)
```

- `tests/Architecture/LayerDependencyTest`: `Domain` imports no Symfony / Doctrine / HTTP / brick class in public
  signatures (`brick/math` used inside VOs only, never in a signature); `Application` depends on ports only; bridges
  are the only code importing optional packages; no `persist(`, `flush(`, `clear(` or `resetManager(` in `src/`.
- Both host kinds (REQ-ARCH-001): public surface = interfaces + VOs; nothing to extend; entities are bundle-owned and
  referenced by id; events carry VOs only.

## 5. Domain model

All `final readonly`, validated in the constructor, in `Nowo\CurrencyKitBundle\Domain\…`.

### 5.1 Currency codes

| VO | Rule |
|---|---|
| `CurrencyCode` | `^[A-Z]{3}$`, exact (no trim, no case folding: `usd` is refused with `InvalidCurrencyCodeException`, so a typo never silently converts); must be in the **bundled ISO 4217 table** (`CurrencyCatalogueInterface`). `minorUnits(): int` (ISO exponent: JPY 0, EUR 2, KWD 3; codes with exponent `N.A.` refused), `isActive(): bool` |
| ISO table | `src/Resources/currency/iso4217.php`: code, numeric, exponent, active, withdrawn-on. Generated from the ISO 4217 maintenance agency lists (current + historic) by a dev script; a test pins the generation date and the CHANGELOG notes table updates. Not symfony/intl: its fraction digits are CLDR display digits and differ from ISO for some codes (QC-4) |
| X-codes | `XAU XAG XPT XPD XDR XSU XUA XBA–XBD XTS XXX` refused in 0.1 (QC-5) |
| Historic codes | Accepted (`isActive() = false`) so past dates convert when the stored source had them (the ECB full-history feed carries withdrawn currencies); a current-date conversion simply finds no rate |
| `CurrencyCatalogueInterface` | Port with the bundled default; a host may decorate it (for a new ISO code before the bundle updates) |

### 5.2 Money and amounts (decision: decimal strings, not minor units)

| VO | Rule |
|---|---|
| `Money` | `amount` (canonical decimal string) + `CurrencyCode`. Grammar `^-?(0\|[1-9]\d{0,17})(\.\d{1,12})?$`: up to 18 integer digits, up to **12** fraction digits, no exponent, no `+`, no thousands separators, no locale input. Canonical form strips trailing fraction zeros (`1.50` → `1.5`; equality on the canonical string). `-0` normalised to `0`. Named constructors: `Money::of(string, CurrencyCode)`, `Money::ofMinor(int $minor, CurrencyCode)` (uses the ISO exponent), `toMinor(RoundingMode)` for callers that store minor units. **No float input** anywhere (a float is a `TypeError`; PHPStan rule) |
| Why decimal strings | Minor units cannot hold sub-cent list prices (F3) and depend on the exponent of each currency; decimal strings round-trip through JSON, DB `DECIMAL` and logs without loss. Minor units stay available at the edges (`ofMinor` / `toMinor`) |
| Bridges | `MoneyPhpBridge::fromMoneyPhp(\Money\Money)` / `toMoneyPhp(...)`, `BrickMoneyBridge::fromBrick(\Brick\Money\Money)` / `toBrick(...)`; only in `Bridge\*`, never in a port |
| `Rate` | `base` (always `EUR` in 0.1), `quote` `CurrencyCode`, `value` decimal string `> 0`, up to 12 integer and 12 fraction digits, `RateDate`, `SourceKey`, `RateOrigin` |
| `RateDate` | `Y-m-d` calendar date of the **source's publication calendar** (Europe/Berlin for ECB); no time |
| `SourceKey` | `^[a-z][a-z0-9_]{0,31}$` (`ecb`, `mirror`, `manual`, host keys), exact |
| `SourceLabel` | Display text ≤ 40 chars, no control / format characters (`ECB`; mirror default `ECB`, QC-7) |

### 5.3 Conversion result

```php
final readonly class ConvertedAmount
{
    public Money $original;
    public Money $amount;              // rounded to $scale (default: target ISO minor units) with $roundingMode
    public string $exactAmount;        // scale 12, HALF_EVEN, never rounded to the currency (sums, caps)
    public string $rate;               // effective original → target rate, scale 12 (cross through EUR)
    public RateDate $rateDate;         // the publication date actually used (may be earlier than $requestedDate)
    public RateDate $requestedDate;    // the date asked for (today in the source calendar when $on is null)
    public SourceKey $source;          // source of the rate used (`manual` when an override applied to either leg)
    public SourceLabel $sourceLabel;
    public bool $isReference;          // always true (kept so callers can assert it, Beacon request)
    public bool $stale;                // true only with conversion.on_stale = flag (§9.4)
    public ?string $manualRateId;      // UUID of the manual rate used on any leg, else null
    /** @var list<RateLeg> */          // 0 legs (same currency), 1 (EUR ↔ X) or 2 (X → EUR → Y), each with its own date and source
    public array $legs;
}
```

- **Rounding:** `RoundingMode` enum (own: `HalfUp`, `HalfEven`, `Down`, `Up`, `HalfDown`, `Ceiling`, `Floor`),
  default **`HalfUp`** for the rounded amount (QC-1); exact amount and rates use `HalfEven` at scale 12.
- **Same currency:** returns the original amount, rate `1`, no legs, `rateDate` = requested date, source `identity`
  (no storage read).
- `ConversionOptions` (optional 4th argument): `?int $scale` (0–12), `?RoundingMode`, `?bool $allowStale`.

### 5.4 Enums

`RateOrigin { Daily; History90; History; Api; ManualRate; Import }`, `SyncOutcome { Stored; Unchanged; Changed;
NotYetPublished; Disabled; Failed }`, `ManualRateStatus { Active; Ended }`, `RoundingMode`, `FetchFeed { Daily;
Last90Days; FullHistory; Range }`.

### 5.5 Exceptions (`Domain\Exception`, base `CurrencyKitException`)

| Exception | When | Host-facing code (UI / future API) |
|---|---|---|
| `InvalidCurrencyCodeException` | Not `^[A-Z]{3}$`, not in ISO table, X-code | `CURRENCY_001` (422) |
| `InvalidAmountException` | Grammar §5.2 | `CURRENCY_002` (422) |
| `RateNotAvailableException` | `reason`: `unknown_for_source`, `before_history`, `no_publication_in_lookback`, `future_date` | `CURRENCY_003` (404 in UI) |
| `StaleRateException` | Latest usable publication older than `max_staleness_days` and `on_stale: fail` | `CURRENCY_004` (503 in UI) |
| `OutboundDisabledException` | A sync / backfill while `outbound.enabled: false` | `CURRENCY_005` |
| `RateSourceUnavailableException` (transient) | Timeout, 5xx, DNS, guard refusal, 3xx | `CURRENCY_006` (502) |
| `RateSourceResponseException` (permanent) | Bad XML / JSON, size cap, wrong base, implausible value (§8.5) | `CURRENCY_007` (502) |
| `StaleManualRateException` | Manual rate edited on an old version | `CURRENCY_008` (409, domain conflict: no `X-Conflict-Code`) |
| `ManualRateOverlapException` | Two active manual rates for the same currency would cover the same date | `CURRENCY_009` (409) |

Lock conflicts are not wrapped: transaction-doctrine's `ConcurrencyConflict` reaches its responder (`CONFLICT_001` /
`CONFLICT_002`, §15). Messages are static English strings with no amount, id or input in them.

## 6. Storage

### 6.1 Conventions

- Table prefix `doctrine.table_prefix` (default `nowo_`, REQ-ORM-002), written `<p>` below.
- **Connection:** the connection of `doctrine.entity_manager` (default `null` = the default manager). Writes and reads
  use DBAL on that connection inside transaction-doctrine write units (`TransactionRunnerInterface` for the
  connection). The bundle **never** calls `persist`, `flush`, `clear` or `resetManager` on any EM (F5, QC-3).
- **Entities** `ReferenceRate`, `RatePublication`, `RateChange`, `ManualRate` are plain classes mapped with XML
  (default) and attribute twins (`doctrine.mapping: xml|attribute`), `read-only="true"`; they exist so
  `schema:update` / `migrations:diff` see the tables and a standard host can query them read-only. A test asserts the
  two mappings give the same schema.
- Ids `string(36)` UUIDv7 (`IdGeneratorInterface`, default `symfony/uid`), binary. Codes and keys (`currency`,
  `source`, `actor_id`) declared binary **NO PAD** (`utf8mb4_0900_bin` MySQL 8, `utf8mb4_nopad_bin` MariaDB) through a
  metadata listener; PostgreSQL / SQLite exact by default (test). Rows read by key are re-checked with `===` in PHP.
- Rates: `DECIMAL(24,12)` (read as string, both DBAL versions). Instants `datetime_immutable` UTC, second precision.
- No migrations shipped (house style); `docs/INSTALLATION.md` carries reference DDL per platform generated by a test;
  `nowo:currency-kit:schema-check` compares the live schema.

### 6.2 Tables

| Table | Columns (beyond `id`) | Keys | Write rule |
|---|---|---|---|
| `<p>currency_rate_publication` | `source` (32), `rate_date` (date), `origin` (`daily` \| `history_90` \| `history` \| `api` \| `import`), `currency_count` (smallint), `content_hash` (char 64: sha256 of the sorted `CODE=value` lines), `first_stored_at`, `last_seen_at`, `version` (int) | **unique `(source, rate_date)`**; idx `(source, rate_date DESC)` | INSERT on first sight; UPDATE `last_seen_at` / `content_hash` / `version` under `FOR UPDATE` of this row (§8.3) |
| `<p>currency_rate` | `source`, `rate_date`, `currency` (char 3), `rate` (`DECIMAL(24,12)`, EUR → currency), `publication_id` | **unique `(source, rate_date, currency)`**; idx `(source, currency, rate_date DESC)` (lookback) | INSERT; UPDATE of `rate` only together with a `currency_rate_change` row in the same unit |
| `<p>currency_rate_change` | `source`, `rate_date`, `currency`, `old_rate` NULL (NULL = currency added to a published day), `new_rate` NULL (NULL = currency removed), `origin`, `detected_at`, `applied` (bool), `run_id` (UUID) | idx `(source, rate_date)`, `(detected_at)` | **INSERT only** (append-only in code; architecture test) |
| `<p>currency_manual_rate` | `currency`, `rate` (`DECIMAL(24,12)`, EUR → currency), `valid_from` (date), `valid_until` (date NULL = open), `reason` (5–500, required), `status` (`active` \| `ended`), `created_by` (actor ref 128 NULL), `created_at`, `updated_by`, `updated_at`, `version` (int) | idx `(currency, status, valid_from)` | INSERT; UPDATE under version; never DELETE (ending = `status ended` + `valid_until`) |
| `<p>currency_sync_lock` | PK `name` (64); `locked_until`, `holder` (run id) | — | Only for `sync.lock.store: database` (§8.2) |

No foreign keys to host tables. Retention: rates, publications and changes are kept (public data, small: about 30
rows per business day ≈ 8 000 rows a year per source). `nowo:currency-kit:rates:prune --before=<date>` exists for
hosts that want to trim, refuses to prune inside `conversion.max_lookback_days` of today, and never prunes changes or
manual rates.

## 7. Ports

All in `Nowo\CurrencyKitBundle\Domain\Port`; each has a default; configured by service id.

### 7.1 Rate sources

```php
interface RateSourceInterface                    // tagged nowo_currency_kit.rate_source
{
    public function key(): SourceKey;
    public function label(): SourceLabel;
    /** Feeds this source can serve; the synchronizer picks the smallest one that covers the gap. */
    public function supports(FetchFeed $feed): bool;
    /**
     * Fetches and parses; no storage, no lock, no transaction. EUR-based result (an adapter rebases if its feed is not).
     * @throws RateSourceUnavailableException (transient) | RateSourceResponseException (permanent) | OutboundDisabledException
     */
    public function fetch(FetchRequest $request): RateBatch;   // RateBatch: list<DayRates{RateDate, map<CurrencyCode, decimal>}>
    public function earliestDate(): ?RateDate;                  // ECB: 1999-01-04
}
```

| Adapter | Feed | Default URL | Notes |
|---|---|---|---|
| `EcbXmlSource` (key `ecb`, label `ECB`) | `Daily` | `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml` | One `Cube time=…` day |
| | `Last90Days` | `…/eurofxref-hist-90d.xml` | |
| | `FullHistory` | `…/eurofxref-hist.xml` | Only from `rates:backfill` (QC-10) |
| `FrankfurterCompatibleSource` (key configurable, e.g. `mirror`) | `Daily`, `Range` | host `base_uri` (self-hosted) | `GET {base}/latest?base=EUR`, `GET {base}/{Y-m-d}?base=EUR`, `GET {base}/{from}..{to}?base=EUR`; JSON; checks `base == "EUR"` (or rebases when `rebase: true`); `amount` must be `1` |
| `ManualRateSource` (key `manual`) | none | — | Never fetches; exists so air-gapped installs can set `conversion.source: manual` and convert from manual rates only (§10) |
| Host sources | any | host | Tagged service; same contract and tests (`RateSourceContractTestCase` shipped in `tests/` and the dist kit) |

`EcbXmlParser` (shared by the sources and `rates:import`): `XMLReader`, see §13.3. Values parsed as strings from the
attribute text (never through float). Frankfurter JSON values are numbers: decoded, then turned into the shortest
round-trip string (`serialize_precision = -1` semantics through `json_encode` of the single value) and validated by
the `Rate` grammar.

### 7.2 Outbound fetcher (the only HTTP code)

```php
interface OutboundFetcherInterface
{
    /** GET with the guard, no redirects, timeout, size cap; returns the decoded body. */
    public function get(string $url, FetchLimits $limits, bool $allowPrivate = false): FetchedBody;
}
```

Default `GuardedHttpFetcher`:

1. `outbound.enabled: false` ⇒ `OutboundDisabledException` before anything else (no DNS either).
2. `RuntimeOutboundUrlGuard::httpClientOptions($url)` (pins the resolved public IP; `allowPrivate` only when the
   source sets `allow_private_network: true`, for a self-hosted mirror on a private network; cloud metadata stays
   blocked whatever the setting). `UnsafeOutboundUrlException` ⇒ `RateSourceUnavailableException` (`guard_refused`).
3. Options: `max_redirects: 0`, `timeout` (idle, default 10 s), `max_duration` (default 30 s; 120 s for full history),
   headers `User-Agent: nowo-currency-kit/<version> (+<outbound.contact_url>)` (contact URL configurable, default the
   bundle repository URL; never a person's e-mail), `Accept: application/xml` or `application/json`.
4. Streams the body with `$client->stream()` and aborts past `max_bytes` (counted on decoded bytes, so a compressed
   bomb is caught). 3xx ⇒ `redirect_refused`; 4xx ⇒ permanent; 5xx / timeout / transport ⇒ transient.
5. A new HTTP client is not kept per request: the injected `HttpClientInterface` is stateless; no response, stream or
   handle survives the call.
6. Retries: up to `outbound.retries` (default 2) for transient failures, backoff 2 s then 8 s, only from the console /
   Messenger path; the UI "sync now" makes one attempt (no sleeping in a web worker).
7. HttpLog bridge names the calls `currency_kit.<source key>`.

### 7.3 Rate store (Application port, DBAL default)

`RateStoreInterface`: `latestPublication(SourceKey): ?PublicationInfo`, `publicationOnOrBefore(SourceKey, RateDate,
int $lookbackDays): ?PublicationInfo`, `rate(SourceKey, RateDate, CurrencyCode): ?string`, `ratesOn(SourceKey,
RateDate): map`, `storeDay(...)` (write unit body, §8.3), `activeManualRate(CurrencyCode, RateDate):
?ManualRateInfo`, manual-rate writes (§10). Lookback query: `WHERE source = ? AND rate_date <= ? AND rate_date >= ?
ORDER BY rate_date DESC LIMIT 1` on the publication index (two indexed queries per leg at most).

### 7.4 Other ports

| Port | Default | Purpose |
|---|---|---|
| `Psr\Clock\ClockInterface` | native clock | "Today" in the source calendar; `MockClock` in tests |
| `CurrencyKitActorResolverInterface::current(): ?ActorRef` | security user identifier (`getUserIdentifier()`), null in CLI unless `--actor` | `created_by` / audit on manual rates and UI syncs; `ActorRef` 1–128 bytes exact |
| `CurrencyKitAuditSinkInterface::record(AuditEntry)` | `PsrLogAuditSink` (one structured line) | `currency_kit.manual_rate.{created,updated,ended}`, `currency_kit.rate.changed`, `currency_kit.sync.manual_trigger`, `currency_kit.import`; AuditKit bridge |
| `CurrencyCatalogueInterface` | bundled ISO table | §5.1 |
| `TargetCalendarInterface` | `TargetCalendar` (1 Jan, Good Friday, Easter Monday, 1 May, 25–26 Dec; weekends) | Expected publication days for scheduling and staleness reports only; never used to invent a rate |
| `CurrencyKitAccessCheckerInterface` | `ConfigurableCurrencyKitAccessChecker` | §13.1 |

## 8. Sync algorithm

### 8.1 Schedule

- ECB publishes on TARGET business days around **16:00 CET/CEST**, calendar Europe/Berlin. The Scheduler bridge
  (`scheduler.enabled: true`, needs `symfony/scheduler`) registers `SyncReferenceRatesMessage` **Mon–Fri at 16:15,
  17:00, 18:30 Europe/Berlin** (`sync.times`, configurable) and once at 08:00 (catch-up after downtime). Without
  Scheduler, `docs/USAGE.md` gives the cron lines for `nowo:currency-kit:rates:sync`.
- A run before the day's publication finds the daily feed still on the previous day ⇒ `NotYetPublished`, exit 0
  (later runs pick it up). A TARGET holiday is reported as `NotYetPublished` with `expected: false`.

### 8.2 Run

1. **Run lock** (one sync per source at a time across workers and hosts): `sync.lock.store: database` (default; row
   in `currency_sync_lock` claimed by `UPDATE … SET locked_until = now + ttl, holder = ? WHERE name = ? AND
   locked_until < now`, inserted on first use with insert-or-ignore) or `lock_factory` (`symfony/lock`, shared store).
   The run lock is a lease, not a row lock held during HTTP: no transaction is open while fetching (F6). TTL 10 min,
   renewed between days. A second run that finds the lease held exits 0 with `skipped: running` (console) or answers
   409 + `Retry-After` `CONFLICT_001` (UI "sync now").
2. **Gap:** `last = latestPublication(source)`; `today` in Europe/Berlin.
   - no row ⇒ `Last90Days` (first install) — or nothing when `sync.initial: none`;
   - `last` ≥ previous TARGET day ⇒ `Daily`;
   - gap ≤ 90 days ⇒ `Last90Days` (or `Range` for an API source);
   - gap > 90 days ⇒ `Last90Days` and a warning `gap_exceeds_90_days`: older days need `rates:backfill` (§8.4).
3. **Fetch + parse** (no lock on rows, no transaction). Retries per §7.2.
4. **Validate** (§8.5) the whole batch before writing anything; a permanent failure stores nothing.
5. **Store** each day in its own write unit (§8.3), oldest first. A lock conflict replays the unit (transaction-doctrine,
   3 attempts, jitter); a final conflict stops the run with exit 1 and the days already stored stay stored (each unit
   is complete on its own; the next run continues).
6. **After commit:** events `ReferenceRatesPublished` (per new day), `ReferenceRateChanged` (per change), audit lines,
   cache invalidation (§16).
7. Exit codes: 0 stored / unchanged / not yet published / skipped; 1 transient failure or final conflict; 2 permanent
   source error or validation failure; 3 outbound disabled (so a misconfigured cron is visible).

### 8.3 Storing one day (write unit, REQ-RUNTIME-003)

```
BEGIN
  INSERT publication (source, rate_date, …) ON CONFLICT / ON DUPLICATE KEY DO NOTHING       -- natural unique key
  SELECT … FROM publication WHERE source = ? AND rate_date = ? FOR UPDATE                    -- one row, fixed order
  existing = SELECT currency, rate FROM rate WHERE source = ? AND rate_date = ?
  if existing == incoming (same codes, numerically equal values): UPDATE last_seen_at; COMMIT ⇒ Unchanged
  else:
    INSERT new currencies (unique (source, rate_date, currency));
    for each differing value: INSERT rate_change(old, new, applied = policy == replace); UPDATE rate if replace
    for each currency missing from the incoming day: INSERT rate_change(old, NULL, applied = false)  -- never deleted
    UPDATE publication SET content_hash, currency_count, version = version + 1, last_seen_at
COMMIT ⇒ Stored | Changed
```

- Two concurrent syncs of the same day: the second waits on the publication row, then sees equal rows ⇒ `Unchanged`.
  Nothing is duplicated (unique keys), nothing is lost (the lock serialises the compare-and-write).
- **Changed published value** (the "never overwrite silently" rule): `sync.on_changed_value: replace` (default,
  QC-6) applies the new value **and** writes the change row, a `warning` log, an audit line and the
  `ReferenceRateChanged` event; `keep` stores only the change row with `applied = false` and the event, and
  `rates:changes --apply <id>` applies it later (audited). A **removed** currency is never deleted from a stored day.
- A day arriving from a **different feed** (90-day file vs daily file) with equal values is `Unchanged`: values are
  compared numerically (`1.0812` = `1.08120`).

### 8.4 Backfill and import

- `nowo:currency-kit:rates:backfill --source=ecb --from=Y-m-d [--to=Y-m-d]`: uses `FullHistory` (ECB) or `Range` (API,
  in chunks of `backfill.chunk_days`, default 366); stores days from `--from` (never before `earliestDate()`), one write
  unit per day in batches, progress bar, resumable (already stored days are `Unchanged`). Limits: `backfill.max_days`
  (default 10 000 ≈ the whole ECB history), full-history body cap 64 MiB (§13.3), memory flat (streaming parser,
  days written as they are read). Never automatic (QC-10).
- `nowo:currency-kit:rates:import <file> --source=ecb [--format=ecb-xml|csv]`: same parser and limits on a local
  file, for **air-gapped installs** that receive ECB files by other means. CSV: `date,currency,rate` with header,
  RFC 4180, ≤ 64 MiB. Imports store with `origin = import`, are audited, and follow §8.3 (changed values logged).

### 8.5 Validation of a batch (before any write)

- Base EUR; no EUR quote; codes in the ISO table and not X-codes (an unknown code in a feed is skipped with a warning,
  never stored; the rest of the day is stored).
- Value grammar, `> 0`, ≤ 12 fraction digits after canonicalisation.
- Plausibility (`sync.max_daily_change_percent`, default 50): a value that differs from the same currency's previous
  stored day by more than that percent fails the day as `implausible_change` (a corrupted or hostile feed must not
  move every price); `rates:sync --accept-implausible <CODE>` or a manual rate handles real jumps (audited).
- Dates: not after today (Europe/Berlin), not before `earliestDate()`, no duplicate date in one batch, ≤ 1 day per
  date per source.
- The daily feed's day older than the stored latest ⇒ `Unchanged` (mirror lagging), not a rewrite.

## 9. Conversion algorithm

### 9.1 Steps (`CurrencyConverter::convert`)

1. Normalise input: `Money` or `(string $amount, CurrencyCode|string $currency)` ⇒ `Money` (§5.2); target
   `CurrencyCode`. Same currency ⇒ identity result (§5.3).
2. **Requested date**: `$on === null` ⇒ today in the source calendar (Europe/Berlin for ECB, `source_timezone` for
   others) from the PSR clock. Otherwise the **calendar date of `$on` converted to the source calendar** (a
   `DateTimeImmutable('2026-11-19 23:30 America/New_York')` is 2026-11-20 in Berlin). A date-only VO
   (`RateDate`) may be passed through `ConversionOptions::onDate` to avoid time-zone surprises (QC-11).
3. **Future** (requested date after today in the source calendar) ⇒ `RateNotAvailableException(future_date)` (QC-11).
4. **Legs** (pivot EUR): from ≠ EUR ⇒ leg `EUR→from`; to ≠ EUR ⇒ leg `EUR→to`. Per leg:
   a. an **active manual rate** for the currency whose window covers the requested date ⇒ use it (rate date = the
      requested date, source `manual`) — §10 precedence;
   b. else the active source's **publication on or before** the requested date within `max_lookback_days`
      (default 7 calendar days; the longest regular TARGET gap is Thursday → Tuesday at Easter);
   c. that publication must contain the currency; if it does not, look further back **only** within the same
      lookback window (a currency missing on one day only); none ⇒ `RateNotAvailableException(unknown_for_source)`
      — a suspended currency never converts at a months-old rate;
   d. requested date before the source's earliest stored publication ⇒ `before_history` (no extrapolation; the
      message tells the admin to run a backfill).
5. **Rate**: `rate = r(EUR→to) / r(EUR→from)` at scale 12 HALF_EVEN (one leg: `r` or `1 / r`); `exact = amount ×
   rate` at scale 12; `amount` rounded to `ConversionOptions::scale ?? minorUnits(to)` with the rounding mode.
6. **Rate date** reported = the older of the two legs' dates (both legs are in `legs`); `stale` per §9.4.
7. No network, no write, no lock: at most 2 indexed queries per leg on a memo miss (§16).

`CurrencyConverterInterface` also has `rate(CurrencyCode $from, CurrencyCode $to, ?DateTimeInterface $on = null):
ResolvedRate` (same result without an amount) and `convertMany(list<Money>, CurrencyCode, ?DateTimeInterface)`
(one resolution per distinct currency and date).

### 9.2 Which date, in one table

| Case | Result |
|---|---|
| Business day after publication | That day's rate, `rateDate` = that day |
| Business day before ~16:00 Berlin, `$on = null` | Previous publication, `rateDate` = previous day (honest: today's is not out yet) |
| Saturday / Sunday / TARGET holiday | Last publication within 7 days, its date reported |
| Future date | `RateNotAvailableException(future_date)` |
| Before stored history | `RateNotAvailableException(before_history)` |
| Currency not offered by the source on or near that date | `RateNotAvailableException(unknown_for_source)` |
| Manual rate covers the date | Manual rate, source `manual`, `manualRateId` set |
| Latest data too old (§9.4) | `StaleRateException`, or `stale = true` with `on_stale: flag` |

### 9.3 Unknown and historic currencies

Valid ISO code without data ⇒ `RateNotAvailableException(unknown_for_source)` (never zero, never 1). Historic code on
a past date with stored data ⇒ converts. Invalid code ⇒ `InvalidCurrencyCodeException` before any query.

### 9.4 Staleness

- `conversion.max_staleness_days` (default 10 calendar days) measured from the requested date to the used
  publication, only for **`$on = null` or today** (an explicit past date that resolves within the lookback is never
  stale). Beyond the lookback a past date already fails (§9.1 4b); staleness matters for "now" when sync has stopped.
- `conversion.on_stale: fail` (default, QC-9): `StaleRateException`; `flag`: result with `stale = true`, a warning
  log once per request and the `ReferenceRatesStale` event at most once per hour per source (shared cache key or the
  publication row's `last_seen_at`; no process state). Manual rates are never stale while their window covers the
  date.
- `nowo:currency-kit:rates:show --health` exits 1 when the latest publication is older than the threshold (health
  checks, HealthKit probe recipe).

## 10. Manual rates (overrides) and air-gapped installs

- A manual rate is EUR → currency, a window `[valid_from, valid_until]` (until null = open), a reason (5–500 chars),
  an actor and a version. **Precedence:** an active manual rate covering the date **wins** over the source's rate for
  that currency on that date (QC-6b); results carry `source = manual` and the manual rate id. At most one active
  manual rate per currency per date: creating or moving one checks overlap under a lock of the existing rows for that
  currency (`SELECT … FOR UPDATE` on `(currency, status)` ordered by id; then insert) ⇒ `ManualRateOverlapException`
  409 `CURRENCY_009`.
- Edits: `ManualRateService::update(id, input, int $expectedVersion)`; stale ⇒ `StaleManualRateException` 409
  `CURRENCY_008` (domain conflict, form re-rendered with the current values). Ending: `end(id, RateDate $lastDay,
  expectedVersion)`; never deleted (audit trail; conversions recorded with the id stay explainable).
- Validation: same `Rate` grammar; plausibility against the source's latest value (`manual.max_deviation_percent`,
  default 50) unless `--force` / a confirmed checkbox (audited with `forced: true`); windows may start in the past
  (re-stated history) only with `manual.allow_past: true` (default false, QC-6b).
- Audit: every create / update / end writes `currency_kit.manual_rate.*` with actor, currency, old and new rate,
  window, version (never free text beyond the reason).
- **Air-gapped:** `outbound.enabled: false` + `conversion.source: manual` (only manual rates) or + `rates:import`
  of ECB files (`conversion.source: ecb`). The sync command exits 3 with a clear message; the UI hides "sync now" and
  shows "outbound calls disabled".
- Pivot: manual rates in 0.1 are always against EUR (cross rates stay consistent). A form helper accepts "1 USD = x
  MXN" and stores the EUR-based equivalent using the current EUR→USD rate, showing both (0.2 UI).

## 11. Configuration tree

```yaml
nowo_currency_kit:
    doctrine:
        entity_manager: null                  # manager whose CONNECTION the bundle uses; never flushed (§6.1)
        table_prefix: nowo_
        mapping: xml                          # xml | attribute
    sources:
        ecb:
            enabled: true
            label: ECB
            daily_url: 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml'
            last_90_days_url: 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml'
            history_url: 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml'
        frankfurter:                          # Frankfurter-compatible API, off by default
            enabled: false
            key: mirror
            label: ECB                        # what users see; QC-7
            base_uri: null                    # https://rates.internal.example/v1 (required when enabled)
            timezone: Europe/Berlin
            allow_private_network: false      # self-hosted mirror on a private network
            rebase: false                     # accept a non-EUR base and rebase to EUR
    conversion:
        source: ecb                           # active source key: ecb | <frankfurter key> | manual | host key
        max_lookback_days: 7                  # 4..31
        max_staleness_days: 10                # 1..366
        on_stale: fail                        # fail | flag
        rounding_mode: half_up                # half_up | half_even | down | up | half_down | ceiling | floor
    sync:
        initial: last_90_days                 # last_90_days | none
        times: ['16:15', '17:00', '18:30']    # Europe/Berlin, Mon–Fri (Scheduler bridge)
        catch_up_time: '08:00'
        on_changed_value: replace             # replace | keep (§8.3)
        max_daily_change_percent: 50          # 1..1000
        lock:
            store: database                   # database | lock_factory
            lock_factory: lock.factory
            ttl_seconds: 600
    backfill:
        max_days: 10000
        chunk_days: 366
    outbound:
        enabled: true                         # false = no HTTP and no DNS at all (air-gapped)
        timeout: 10                           # seconds, idle (1..60)
        max_duration: 30                      # seconds (1..120); full history uses max(this, 120)
        retries: 2                            # 0..5, console / Messenger only
        contact_url: 'https://github.com/nowo-tech/CurrencyKitBundle'
        max_bytes: { daily: 262144, last_90_days: 4194304, history: 67108864, api: 4194304 }
    manual:
        max_deviation_percent: 50
        allow_past: false
    scheduler:
        enabled: false                        # registers the RecurringMessage provider (needs symfony/scheduler)
        transport: null                       # Messenger routing hint for SyncReferenceRatesMessage
    cache:
        pool: null                            # optional PSR-6 pool for resolved rates (§16)
        ttl: 300
    twig:
        enabled: true                         # when symfony/twig-bundle is present; needs ext-intl
        on_missing_rate: note                 # note | original | exception
        date_format: iso                      # iso (Y-m-d) | locale (IntlDateFormatter MEDIUM)
    ui:
        enabled: false                        # routes opt-in (REQ-UI-001)
        path_prefix: /admin/currency
        layout_template: '@NowoUiKit/layout/app.html.twig'
        page_size: 50
    security:                                 # REQ-UI-002, house access pattern (§13.1)
        access_roles: [ROLE_ADMIN]
        access_checker: null
        allow_unauthenticated: false          # DEV/DEMO only
        dashboard_roles: []                   # view rates page; empty = access_roles
        manage_roles: [ROLE_ADMIN]            # manual rates, sync now
    audit:
        sink: null                            # service id; AuditKit bridge when installed, else PSR log
```

Container validation: `conversion.source` names an enabled source or `manual`; `frankfurter.enabled` needs
`base_uri` (https unless `allow_private_network`); `twig.enabled` needs `ext-intl`; `ui.enabled` needs UiKit, FormKit
and SecurityBundle unless `allow_unauthenticated` (compile error, REQ-UI-002); `scheduler.enabled` needs
`symfony/scheduler`; `lock.store: lock_factory` needs the service; ranges as commented.

Runtime settings (REQ runtime env / DB): `outbound.enabled`, `sources.frankfurter.base_uri` — and `conversion.on_stale` are registered as `RuntimeSettingDefinition`s (`Bool`,
`HttpsUrl`, enum validators; `source: env` default; `outbound.enabled` `on_error: fail_closed` = treated as false).
Everything else is compile-time.

## 12. Public services, Twig, events, commands

### 12.1 Services

| Interface | Methods |
|---|---|
| `CurrencyConverterInterface` | `convert(Money\|string $amount, CurrencyCode\|string\|null $currency, CurrencyCode\|string $target, ?DateTimeInterface $on = null, ?ConversionOptions $options = null): ConvertedAmount` (when `$amount` is `Money`, `$currency` is `null`), `rate(...)`, `convertMany(...)` |
| `ReferenceRateReaderInterface` | `latest(?SourceKey): ?PublicationView`, `ratesOn(RateDate, ?SourceKey): RateTable`, `history(CurrencyCode, RateDate $from, RateDate $to, ?SourceKey)` (≤ 366 days), `changes(RateDate $from, RateDate $to)` |
| `RateSynchronizerInterface` | `sync(?SourceKey, SyncOptions): SyncReport`, `backfill(...)`, `import(...)` |
| `ManualRateServiceInterface` | `create(ManualRateInput): ManualRateView`, `update(id, input, int $expectedVersion)`, `end(id, RateDate, int $expectedVersion)`, `list(filters, page)` |
| `CurrencyCatalogueInterface` | `get(string): CurrencyCode`, `exists(string)`, `all(bool $activeOnly)` |

### 12.2 Twig (`ReferenceMoneyExtension`, when `twig.enabled`)

- Filter `reference_money(target, on = null, from = null, scale = null)`. Input: `Money`, a money-library object
  through a bridge, or a decimal string / int with `from` given. Floats are refused (`TypeError` in dev, note in prod).
- Output (plain text, **not** marked safe; Twig autoescapes it): `≈ {formatted} ({label} {date})` with the label
  from translations: `≈ 12,30 € (reference rate ECB 2026-11-19)` in `es`, `≈ €12.30 (reference rate ECB 2026-11-19)`
  in `en`. Number + symbol with `NumberFormatter::CURRENCY` for the **request locale** (`RequestStack` per call, never
  stored), the currency's minor units unless `scale`; date ISO by default (unambiguous), localized with `date_format:
  locale`. The source label is a configured string, still escaped.
- Missing rate (`on_missing_rate`): `note` (default) prints `({translated "no reference rate"})` and logs a warning;
  `original` prints nothing; `exception` rethrows (dev / tests). Stale with `on_stale: flag` adds `(stale)`.
- Function `reference_money_parts(...)` returns `{amount, formatted, currency, rate, rate_date, source_label, stale,
  manual}` for hosts that build their own markup (e.g. `<abbr title>`), and `reference_rate_note()` returns the
  translated "Reference rates, for information only" sentence.

### 12.3 Events (`Domain\Event`, dispatched after commit, VOs and ids only)

`ReferenceRatesPublished(source, rateDate, currencyCount, origin)`, `ReferenceRateChanged(source, rateDate,
currency, oldRate, newRate, applied)`, `ReferenceRateSyncFailed(source, reason, transient)`,
`ReferenceRatesStale(source, latestDate, ageDays)`, `ManualRateCreated|Updated|Ended(id, currency, rate, window,
actor)`. Access events in §13.1. A per-call after-commit buffer (local, passed down) releases them; a rollback drops
them.

### 12.4 Console

| Command | Purpose |
|---|---|
| `nowo:currency-kit:rates:sync [--source] [--feed=daily\|90d] [--accept-implausible=CODE]` | §8.2 (`SyncReferenceRatesMessage` handler runs the same code; handler is idempotent and can run twice) |
| `nowo:currency-kit:rates:backfill --from [--to] [--source]` | §8.4 |
| `nowo:currency-kit:rates:import <file> [--source] [--format]` | §8.4, air-gapped |
| `nowo:currency-kit:rates:show [--date] [--currency] [--health]` | Table / health exit code |
| `nowo:currency-kit:rates:changes [--from] [--apply=<id>]` | Change log; apply kept changes (audited) |
| `nowo:currency-kit:rates:manual:{set,end,list}` | Manual rates from CLI (`--actor`, `--reason` required) |
| `nowo:currency-kit:rates:prune --before` | §6.2 |
| `nowo:currency-kit:convert <amount> <from> <to> [--date]` | Debug helper, prints the full `ConvertedAmount` |
| `nowo:currency-kit:schema-check` | Columns, collations, indexes |

## 13. Security

### 13.1 Admin routes and the house access pattern (REQ-UI-002)

- UI routes exist only when `ui.enabled: true` **and** the host imports `@NowoCurrencyKitBundle/config/routes.php`
  with its prefix. Route names `nowo_currency_kit_*`; all under `ui.path_prefix`; no catch-all, no listener that
  touches other routes (the bundle never answers host routes; its exception mapping applies only to requests whose
  `_route` starts with `nowo_currency_kit_`). The prefix is a container parameter
  (`nowo_currency_kit.ui_path_prefix`) for the host's `access_control`.
- Config `security.*` as §11. **Area checker** `CurrencyKitAccessCheckerInterface { canViewRates(object $user): bool;
  canManageManualRates(object $user): bool; canTriggerSync(object $user): bool; }` with
  `ConfigurableCurrencyKitAccessChecker` (roles: `dashboard_roles` ⇒ view, `manage_roles` ⇒ manage and sync; user must
  also hold one of `access_roles`) and `AllowAllCurrencyKitAccessChecker` (only selectable with
  `allow_unauthenticated: true`, demos). Host checker by `security.access_checker`.
- **Per-record events** `CurrencyKitEvents::MANUAL_RATE_ACCESS_CHECK` (`ManualRateAccessCheckEvent`: user, manual
  rate id, currency, action `view|edit|end`; `grant()` / `deny()` / default = the area decision) and
  `CurrencyKitEvents::SYNC_ACCESS_CHECK` (source key).
- `CurrencyKitGuard` (called by every controller action first): no user and not `allow_unauthenticated` ⇒ 302 to
  login / 401 JSON; area false ⇒ 403; event deny ⇒ 403. Default = **deny** for everything when no role matches.
- Every POST: CSRF (`nowo_currency_kit`), FormKit form, `#[AsWriteUnit]`.

| Action | View | Manage | Sync |
|---|---|---|---|
| Rates page (latest, any date, history chart table, change log) | ✓ | ✓ | |
| Manual rates list | ✓ | ✓ | |
| Create / edit / end manual rate | | ✓ (+ event) | |
| "Sync now" | | | ✓ (`manage_roles`) |

### 13.2 Rate tampering

- Sources only from config (fixed URLs; a host source is code); no URL comes from a request or the DB.
- TLS verification on (HttpClient default; never disabled by config); guard pins the IP; no redirects.
- Plausibility gate (§8.5) and manual deviation gate (§10); every changed value is logged, audited and evented;
  nothing is ever silently replaced or deleted.
- Manual rates need `manage_roles`, a reason and CSRF; conversions report `manualRateId` so a forged override is
  visible wherever the result is shown or stored.
- DB access: the bundle cannot stop someone with SQL write access; `content_hash` per publication lets
  `rates:show --verify` recompute and report rows changed outside the bundle.

### 13.3 XML and JSON parsing safety

- `XMLReader::XML()` / `::open()` on the already-capped body / file with `LIBXML_NONET | LIBXML_COMPACT`, **no**
  `LIBXML_NOENT`, **no** `LIBXML_DTDLOAD` / `DTDATTR`; any `DOCTYPE` node ⇒ reject (XXE and entity expansion cannot
  happen); `libxml_use_internal_errors(true)` restored in `finally`; no `simplexml_load_*` / `DOMDocument` on remote
  data.
- Size caps (decoded bytes, §11 `max_bytes`) before parsing; element depth ≤ 8; ≤ 400 currencies per day; ≤
  `backfill.max_days` days; attribute values ≤ 32 bytes; only the expected elements (`Cube` with `time`, `currency`,
  `rate`) are read, everything else ignored. Namespaces checked (`http://www.ecb.int/vocabulary/2002-08-01/eurofxref`).
- JSON: `json_decode(..., flags: JSON_THROW_ON_ERROR, depth: 8)` on a capped body; shape checked key by key.
- Parse errors never echo the body in messages or logs: only the reason and the byte count.
- Fuzz-style tests: billion laughs, external entity, DOCTYPE, deep nesting, huge attribute, truncated file, gzip bomb
  (through `MockHttpClient` with `Content-Encoding`), duplicate dates, negative / zero / exponent values.

### 13.4 Other

- Twig output is escaped (§12.2); translations contain no HTML.
- CSV import: RFC 4180 parser, no formula evaluation (it only reads); the change-log CSV export in the UI (0.2) uses a
  formula-injection-safe writer.
- Commands that write require `--actor` when no security user exists (audit).

## 14. Privacy

No personal data in rates, publications or changes. Manual rates and audit lines hold the **actor reference** of the
admin who wrote them (legitimate interest: integrity of reference data). That ref is the only personal datum;
`nowo:currency-kit:actor:forget <ref>` sets it to NULL on manual rates (the audit sink owns its own retention). No IP,
no user agent, no tracking. Outbound calls carry no user or tenant data: only the feed URL and the bundle's
User-Agent. LegalKit text is not needed (no processing of data subjects' data beyond the admin ref).

## 15. Concurrency (REQ-RUNTIME-003)

| Write path | Unit | Control | Conflict answer |
|---|---|---|---|
| Store one day (sync, backfill, import, Messenger) | 1 per day | Insert-or-ignore + `FOR UPDATE` on the publication row, unique `(source, rate_date, currency)` | Replay ×3; final ⇒ console exit 1 / UI 409 `CONFLICT_001` + `Retry-After` (+ `X-Conflict-Code`); `CONFLICT_002` when an earlier day of the same UI request committed (CommitLedger) |
| Run lease | 1 (tiny) | Conditional `UPDATE … WHERE locked_until < now` | Held ⇒ console "skipped"; UI 409 `CONFLICT_001` + `Retry-After: <lease left>` |
| Manual rate create | 1 | `FOR UPDATE` on that currency's active rows (by id), overlap check, insert | Overlap ⇒ 409 `CURRENCY_009`; lock ⇒ `CONFLICT_001` |
| Manual rate update / end | 1 | `version` (`UPDATE … WHERE id = ? AND version = ?`, 0 rows ⇒ stale) + the overlap lock when the window moves | Stale ⇒ 409 `CURRENCY_008`; lock ⇒ `CONFLICT_001` |
| Apply kept change | 1 | `FOR UPDATE` publication row; change row applied flag with `WHERE applied = false` | Already applied ⇒ no-op |
| Prune | batches of 1 000 days | Keyset, one transaction per batch | Lock ⇒ replay; exit 1 |

- Runner: `nowo-tech/transaction-doctrine` write units on the configured connection (`forConnection()`), savepoint-
  aware nesting (a host transaction already open ⇒ the unit is a savepoint, rolled back to it on failure; on
  PostgreSQL always rolled back to the savepoint before rethrow).
- Never clears, resets or flushes any EM (DBAL only). Replays hold no state from an earlier attempt (rows re-read,
  actor re-resolved per attempt).
- No HTTP / file I/O inside a unit (fetch before; file import parses one day, writes it, then reads the next).
- READ COMMITTED assumed; locks in a fixed order (publication row, then rate rows by currency; manual rates by id).
- `docs/CONCURRENCY.md` with this table. Real-database CI job "Databases" (MySQL 8.4, MariaDB 11.4, PostgreSQL 17;
  DBAL 3 and 4) is a required check.

## 16. Worker mode (FrankenPHP, kernel reset false; REQ-RUNTIME-002)

- Services hold configuration only. No request, user, locale or rate data in properties or statics.
- **Rate memo:** a per-request memo of resolved `(source, date, currency)` lookups (array in a `RequestScopedRateMemo`
  cleared on `kernel.request`, `kernel.terminate`, `kernel.finish_request` and Messenger `WorkerMessageHandledEvent`;
  also `ResetInterface` with a test; never relied on alone). Keys are public data, never per person.
- **Shared cache (optional)** `cache.pool`: resolved publications keyed by `source|date` plus a version key
  `currency_kit.rates_version` bumped after every commit that writes rates or manual rates; reads check the version
  (one cache read) so a manual rate reaches every worker at once; TTL ≤ 300 s as a bound.
- ISO table: an immutable PHP array loaded from a file (opcache), not mutable state.
- HTTP: no client, response or stream kept; DNS child processes of the guard are per call.
- Locale for Twig from `RequestStack` per call; `NumberFormatter` created per call (cheap) or memoised per request
  only.
- Lease released in `finally`; DBAL connection loss between requests tolerated (reconnect on next query).
- Tests: 2 000 conversions + 200 Twig renders in one kernel alternating two locales and a manual rate created midway
  (the next request sees it, no stale memo), flat memory; Igor and `phpstan-frankenphp` worker rules green.

## 17. Standard vs DDD host (REQ-ARCH-001)

| Topic | Standard Symfony host | DDD / hexagonal host |
|---|---|---|
| API | Inject `CurrencyConverterInterface`; Twig filter | Same; VOs only; host wraps `ConvertedAmount` in its own type |
| Storage | Default EM's connection; attribute or XML mapping; may query `ReferenceRate` read-only | Separate manager / connection by config; XML mapping; host reads through `ReferenceRateReaderInterface` |
| Flush | Bundle never flushes (DBAL) | Same |
| Events | Symfony dispatcher, after commit | Same; host maps to its bus |
| Money types | Bundle `Money`, or the MoneyPHP / Brick bridges | Own Money type mapped at the edge |

Both host kinds run the same functional suite (standard fixture: default EM + attributes; DDD fixture: second
connection, XML mapping, custom actor resolver and access checker).

## 18. UI (0.2.0, optional; UiKit + FormKit)

- **Rates page** `GET {prefix}/` : date picker (default latest), source + label, "Reference rates published by the
  European Central Bank for information purposes; not for payments or accounting" note (translated), publication
  date and `last_seen_at`, table code / name (symfony/intl names in the request locale) / rate / change vs previous
  day / manual badge, stale banner, "outbound calls disabled" banner, "Sync now" button (Sync permission). Keyset
  pager not needed (≤ 400 rows); history per currency `GET {prefix}/currency/{CODE}` (≤ 366 days, table + UiKit
  sparkline).
- **Manual rates** `GET {prefix}/manual`, `GET|POST {prefix}/manual/new`, `GET|POST {prefix}/manual/{uuid}/edit`
  (hidden `version`), `POST {prefix}/manual/{uuid}/end`; the "1 USD = x MXN" helper; deviation confirmation.
- **Change log** `GET {prefix}/changes` (+ CSV).
- Phone-first, no horizontal scroll, axe clean, every string translated ×7, branded try-again page for conflicts.
- Errors: 404 unknown id / disabled UI; 403 guard; 409 per §15; 422 form validation.

## 19. Testing plan (100 % PHP coverage, PHPStan max, no live network)

- **Fixtures:** recorded `eurofxref-daily.xml` (one day), `eurofxref-hist-90d.xml` (trimmed to 10 days + one full
  recorded file), a small full-history sample with historic currencies and a missing-currency day, Frankfurter JSON
  responses (latest, date, range), and hostile files (§13.3). Served through `MockHttpClient`; a test fails if any
  real `HttpClient` is built in the suite. The fixtures' recording date and URL are noted in `tests/Fixtures/README`.
- **Parser:** values as strings byte for byte, dates, namespaces, all hostile cases, caps.
- **Money / maths:** grammar table, canonical form, `ofMinor` / `toMinor` for exponents 0 / 2 / 3, every rounding mode
  at ties (golden table), no float path (PHPStan rule + test), sub-cent cost-cap case (`0.000003 USD` → EUR exact).
- **Conversion:** identity, one leg each way, cross, manual on one leg, weekend, Easter gap, Christmas, before
  16:00 / after, future, before history, missing currency on one day, suspended currency (no rate after lookback),
  time-zone edge (late evening New York = next day Berlin), stale fail / flag, `convertMany` query count.
- **Sync:** first install (90 days), daily, not yet published, gap > 90 days warning, unchanged repeat, changed value
  replace / keep / apply, removed currency kept, implausible change refused and accepted, outbound disabled exit 3,
  transient retries then exit 1, permanent exit 2, redirect refused, guard refused, private mirror allowed only by
  flag, User-Agent header asserted.
- **Concurrency (two DBAL connections; MySQL / MariaDB / PostgreSQL in CI):** two syncs of the same day ⇒ one stored,
  one unchanged, no duplicates; lease race; manual overlap race ⇒ one 409 `CURRENCY_009`; stale manual edit ⇒
  `CURRENCY_008`; injected deadlock ⇒ replay then 409 `CONFLICT_001` + `Retry-After` + `X-Conflict-Code`; committed
  then conflict ⇒ `CONFLICT_002`; connection usable afterwards; host transaction open ⇒ savepoint path.
- **Security:** every route × profile (guest, user without roles, viewer, manager, custom checker, event deny,
  `allow_unauthenticated` demo) ⇒ 302 / 403 / 200; CSRF; host routes untouched by the bundle's listeners.
- **Twig:** locales ×7 golden output, escaping (a label with `<b>` printed escaped), missing-rate modes, stale suffix.
- **Worker:** §16 N-request test; memo reset test; Igor.
- **Both host kinds:** §17 fixtures; XML vs attribute schema equality; reference DDL per platform.
- **Architecture:** §4 rules; no `flush(`/`clear(`; change table has no update/delete path.
- **Demo e2e (0.2):** Playwright rates page, manual rate create / edit / end, sync now (mocked source), WebP
  screenshots, phone width.

## 20. Docs set

README (worker support stated, reference-only warning first), INSTALLATION (DDL), CONFIGURATION, USAGE (converter,
Twig, cron / Scheduler, air-gapped recipe, custom source), CONCURRENCY, FRANKENPHP-WORKER-AUDIT, SECURITY (§13,
residual risks), UPGRADING, CHANGELOG, `docs/SOURCES.md` (feeds, times, attribution "Source: ECB"), Spec Kit
baseline. Docs name the ECB as the data publisher and the Frankfurter-compatible protocol as a wire format only; no
comparison with or citation of other products.

## 21. Error and status mapping (UI now; reused by a future API)

`{"error": "<static message>", "code": "<CODE>"}` and `X-Error-Code` for JSON UI endpoints (none in 0.1); HTML pages
render translated messages. Codes: `CURRENCY_001`–`CURRENCY_009` (§5.5), `CONFLICT_001` / `CONFLICT_002`
(transaction-doctrine responder, `X-Conflict-Code`, `Retry-After` only for 001), `ACCESS_001` (404 hidden),
`ACCESS_002` (403).

## 22. Versioning and slices

| Slice | Version | Content | Size | Audit |
|---|---|---|---|---|
| S0 scaffold | `main`, no tag | Composer, Docker (MySQL 8.4, MariaDB 11.4, PostgreSQL 17, SQLite), Makefile + release-check, CI matrix SF 7.4 / 8.0 / 8.1 × DBs × DBAL 3/4, PHPStan max + `phpstan-frankenphp`, Igor, Rector, CS, coverage gate 100 %, Spec Kit baseline, docs skeleton, Flex recipe, strict config tree | S | Batched |
| **S1 core** | **0.1.0** | §5 domain, ISO table, §6 storage + DDL + schema-check, ECB XML sources + parser + import, Frankfurter-compatible source, manual-rate source, guarded fetcher, sync / backfill / import / show / changes / manual / convert / prune commands, Scheduler bridge, converter (§9), manual rates service (§10, CLI), Twig filter + parts + note, events, audit sink + AuditKit bridge, HttpLog bridge, MoneyPHP / Brick bridges, concurrency + worker tests, 7 locales | M (≈ 1 week) | Batched (no auth / crypto / permission surface yet; data integrity covered by tests) — immediate if the owner prefers (QC-13) |
| S2 UI | 0.2.0 | §18 UI, house access pattern (§13.1), FormKit forms, change-log CSV, demo symfony7 / symfony8 on UiKit, e2e | M (≈ 4 days) | **Immediate** (permissions) |
| Later | 0.3.x+ | Per-tenant manual rates (QC-8), public read API `<prefix>/currency/v1/rates` (QC-12), more sources (other central banks as host-pluggable presets), non-EUR pivot | — | — |
| 1.0.0 | after Beacon adoption of 0.1 / 0.2 | `bundle-req-audit` 100 %, SEC-004 | — | Immediate |

Packagist: owner registers after S0 is on `main` and before the 0.1.0 tag (house rule).

## 23. Beacon integration sketch (informative)

Beacon AI cost caps: `convert(Money::of($usdCost, USD), EUR, $usageAt, new ConversionOptions(scale: 12))`, sum
`exactAmount` per connection and period, compare with the EUR cap, store the `rate`, `rateDate` and `source` next to
the usage row (so a later rate change never rewrites history). Admin pages show
`{{ cost|reference_money('EUR', usage.at) }}`. Sync through the Scheduler bridge on Beacon's worker.

## 24. Owner questions (QC)

| # | Question | Options | Recommendation |
|---|---|---|---|
| QC-1 | Rounding of the rounded amount | a) `HALF_UP` default, configurable; b) `HALF_EVEN` (banker's); c) truncate | **a) HALF_UP**, configurable globally and per call; exact amount (scale 12, HALF_EVEN) always alongside (reference display, not accounting) |
| QC-2 | Money representation and maths library | a) decimal strings (≤ 18 + 12 digits) + `brick/math` required; b) integer minor units + `bcmath`; c) depend on `moneyphp/money` / `brick/money` in the API | **a)**, with `ofMinor` / `toMinor` helpers and optional bridges for both money libraries (rule 3: own VO per port; sub-cent prices need decimals) |
| QC-3 | Storage on the "host EM" | a) configured manager's **connection**, DBAL writes in write units, entities mapped read-only, never `flush`; b) private EM `nowo_currency_kit` in the recipe; c) ORM `persist/flush` on the host EM | **a)**: meets the request (tables on the host manager) and rules 6 / RUNTIME-003 rule 2; a host wanting isolation points `entity_manager` at its own manager |
| QC-4 | Currency table | a) bundled ISO 4217 table (current + historic), generated, updated in minors; b) symfony/intl `Currencies` | **a)**: ISO exponents (CLDR display digits differ), no symfony/intl dependency for the core; intl only for names in the UI |
| QC-5 | ISO X-codes (gold, SDR, test codes) | a) refused in 0.x; b) accepted when a source offers them | **a) refused** (no source offers them; avoids `XTS` / `XXX` surprises) |
| QC-6 | A published day's value changes in a later feed | a) replace + change row + warning + event + audit; b) keep the first value, record the change, apply by command; c) refuse the day | **a) replace** (`on_changed_value: keep` available): the later publication is the correct reference; callers that stored a rate keep theirs |
| QC-6b | Manual rate precedence and past windows | a) manual wins over the source on covered dates, past windows only with `allow_past`; b) manual only fills dates the source lacks | **a)**, with `allow_past: false` by default so an override cannot silently re-state history; overlap refused |
| QC-7 | Label of a Frankfurter-compatible mirror's rates | a) configurable label, default `ECB` (it serves ECB data); b) always the mirror's key | **a)**, configurable; the source **key** stays distinct (`mirror`) in storage and results |
| QC-8 | Scope of rates and manual rates | a) instance-wide (one set, instance admins) in 0.x, per-tenant later; b) per-tenant manual rates now | **a) instance-wide**: rates are public data; per-tenant overrides when the CRM needs negotiated rates (Beacon to confirm, QB-4) |
| QC-9 | Stale data for "now" and network on the conversion path | a) never fetch on conversion; stale > 10 days ⇒ exception (`on_stale: fail`), `flag` optional; b) `flag` by default; c) lazy fetch on miss | **a)**: no hidden latency, works air-gapped, a stopped sync is loud; Beacon may choose `flag` for caps (QB-3) |
| QC-10 | Full-history feed | a) only through `rates:backfill` / `rates:import`; b) automatic when a past date is missing | **a) explicit only** (multi-MB download; never triggered by a request) |
| QC-11 | Dates passed to `convert()` | a) calendar date of `$on` in the source time zone; future refused; `RateDate` accepted for date-only callers; b) "rate known at that instant" (before 16:00 ⇒ previous day for past instants too); c) future ⇒ latest | **a)** (accounting convention "rate of the day"); for `null` it naturally reports the previous day before publication |
| QC-12 | Public HTTP API in 0.x | a) none (PHP + Twig + console + UI); b) `<prefix>/currency/v1/rates` read-only, off by default, OpenAPI | **a) none** until a client needs it; the error codes are already shaped for it |
| QC-13 | Release plan and audits | a) 0.1.0 core (batched audit), 0.2.0 UI (immediate audit), 1.0.0 after REQ audit + SEC-004; b) one 0.1.0 with UI | **a)**: Beacon's caps need only the core; the UI adds the permission surface audited on its own |
| QC-14 | Plausibility gate on feeds | a) refuse a day whose value moved > 50 % vs previous (configurable, override by flag); b) no gate | **a)**: one corrupted or hostile feed must not move every converted price; real jumps handled with `--accept-implausible` or a manual rate |
| QC-15 | Outbound calls default | a) enabled with ECB as the only default host, `outbound.enabled` runtime setting (env / DB), fail-closed; b) disabled until configured | **a)**: zero-config for standard hosts; air-gapped installs set one flag |

## 25. Beacon questions (QB)

| # | Question | Recommendation |
|---|---|---|
| QB-1 | Which currencies and dates do caps convert: USD → EUR only, at the usage date (`$on = usage time`) or one rate per billing period? | Usage date (`$on`), storing rate + rate date with the usage row |
| QB-2 | Do caps need sub-cent exactness (sum `exactAmount`, scale 12) or is the rounded amount enough? | `exactAmount` with `scale: 12` for sums; round only for display |
| QB-3 | When rates are stale (sync stopped), should a cap check fail (block the AI call) or continue with `stale = true` and alert? | Beacon sets `on_stale: flag` and alerts on `ReferenceRatesStale`; the bundle default stays `fail` |
| QB-4 | Will the CRM / billing need per-organization negotiated rates (manual per tenant), or are instance-wide rates enough for 2026? | Instance-wide now; per-tenant in a later minor if quotes need it |
| QB-5 | Storage: default EM connection with `nowo_` tables, or a separate manager? | Default connection, `nowo_currency_*` tables, Beacon migration from the reference DDL |
| QB-6 | Scheduling: Symfony Scheduler bridge on Beacon's worker, or cron lines? | Scheduler bridge (`scheduler.enabled: true`) on the existing transport |
| QB-7 | UI mount and permissions: `/dashboard/admin/currency` behind the instance-admin role; a custom checker or `manage_roles: [ROLE_SUPER_ADMIN]`? | Config roles first; a checker only if the organization model must apply |
| QB-8 | Self-hosted Frankfurter-compatible mirror on Beacon's private network, or ECB direct? | ECB direct (one request a day; no extra service) |

## 26. Answers

_Empty until the owner and Beacon answer. Recorded here with the date; the contract moves to v1.0 when every
question has an answer and the build follows the recommendations meanwhile only after the owner's OK._

### 26.1 Owner answers (2026-11-19)
The owner accepted every recommended answer, QC-1 to QC-15 (including QC-6b), as written above.

### 26.2 Beacon answers (2026-11-19)
- QB-1: convert at the usage date. Beacon stores the rate, the rate date and the source with the usage row.
- QB-2: sum `exactAmount` at scale 12 (brick/math decimal strings). Round only for display.
- QB-3: Beacon uses `on_stale: flag` and alerts on the stale event. The bundle default stays `fail` after 10 days.
- QB-4: instance-wide rates for now; per-organisation overrides later.
- QB-5: default connection, `nowo_currency_*` tables, read-only entities, writes through DBAL. Additional requirements:
  - a host's `doctrine:schema:update --dump-sql` drift gate must see the tables as mapped, with no diff;
  - UPGRADING ships the DDL for hosts that use migrations.
- QB-6: Scheduler bridge, Mon–Fri 16:15 Europe/Berlin. Also run one sync on a cold start when no rate exists yet.
- QB-7: admin path. Beacon uses permission sets, not roles, so the bundle must expose an access checker port plus a `CURRENCY_ACCESS_CHECK` event (house pattern, deny by default) next to the `*_roles` default checker.
- QB-8: ECB direct. Keep the source port so a Frankfurter-compatible source can be added later.
