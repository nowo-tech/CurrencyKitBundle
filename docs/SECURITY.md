# Security

Reporting: see [../.github/SECURITY.md](../.github/SECURITY.md).

## Threat model (0.1)

| Threat | Mitigation |
| --- | --- |
| A hostile or corrupted feed moves every price | Plausibility gate: a value more than `sync.max_daily_change_percent` (50 %) from the previous stored day fails the day (`implausible_change`); real jumps need `--accept-implausible=CODE` (audited) or a manual rate |
| A published value silently replaced | Every changed value writes an append-only change row, a warning, an audit line and `ReferenceRateChanged`; `on_changed_value: keep` keeps the first value until an operator applies the change; a removed currency is never deleted |
| Rows changed directly in the database | `content_hash` per publication; `rates:show --verify` reports the days whose rows no longer match |
| SSRF through a source URL | URLs come from configuration only (never from a request or the database); OutboundUrlGuard pins the resolved public address, blocks cloud metadata, refuses private networks unless the mirror opts in; no redirects; TLS verification never disabled |
| XXE, entity expansion, parser bombs | `XMLReader` with `LIBXML_NONET`, no `LIBXML_NOENT` / `DTDLOAD`; any DOCTYPE refused; depth ≤ 8; attribute values ≤ 32 bytes; ≤ 400 currencies a day; day cap; decoded-byte caps (a compressed bomb is caught); JSON depth 8, shape checked key by key |
| Leaking response bodies | Parse errors carry a reason and a byte count only; exception messages are static |
| Float rounding errors | No float on the money path: decimal strings and `brick/math`; JSON numbers become their shortest round-trip string before the rate grammar |
| A forged manual rate | Manual rates need the manage permission and a reason, are versioned and audited (actor, old and new rate, window); results carry `manualRateId`; a deviation beyond `manual.max_deviation_percent` needs a confirmed force (audited); past windows need `manual.allow_past` |
| Unauthorised admin operations | `CurrencyKitGuard` before every console command: area checker port + `*_ACCESS_CHECK` events, deny by default on the web; writing commands (and `--accept-implausible`) need an actor. Console policy below |
| Two overlapping manual rates | The overlap check holds a lock on the guard row `manual_<CODE>` (always present) before it reads, so concurrent writers are serialised under READ COMMITTED too |
| CSV formula injection | The CSV import only reads text; nothing is evaluated (the 0.2 CSV export will use a formula-safe writer) |
| Twig output injection | Plain text, never marked safe; translations contain no HTML; the source label is escaped |
| Look-alike keys (`ECB` vs `ecb `) | Source, currency, id and actor columns are binary NO PAD on MySQL / MariaDB; keys read back are re-checked with `===` |

## Console access policy (owner decision)

A console command run without a signed-in user is granted by default: an operator with shell access can already read
the database credentials. `*_ACCESS_CHECK` listeners may deny (`getChannel()` is `console`). Risk accepted and
documented: a command run from code inside a web request without a user (a controller calling
`Application::run()`) is granted as well; hosts must never expose the bundle's commands to web users, or deny the
console channel in a listener when they run commands from requests.

## Runtime settings

`outbound.enabled`, `sources.frankfurter.base_uri` and `conversion.on_stale` are not registered as
`nowo-tech/runtime-setting-contract` settings in 0.1.0 (owner decision Q3, planned for 0.2): they are configuration
values (`%env()%` accepted, resolved when the container is built; an unreadable `outbound.enabled` counts as false).

## Privacy

No personal data in rates, publications or changes. Manual rates and audit lines hold the actor reference of the
admin who wrote them (integrity of reference data); `nowo:currency-kit:actor:forget <ref>` removes it from manual rates.
Outbound calls carry no user or tenant data: the feed URL and the bundle's User-Agent only.

## Residual risks

- Someone with SQL write access can change rates; `--verify` detects it after the fact.
- SQLite stores `DECIMAL` as a double: it is for development and tests only (rates beyond 15 significant digits lose
  precision). Use MySQL, MariaDB or PostgreSQL in production.
- Reference rates are not payment or accounting rates; using them for settlement is outside the bundle's purpose.
