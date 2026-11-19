# Data sources

## European Central Bank (default, key `ecb`)

The European Central Bank publishes euro foreign exchange reference rates **for information purposes only**, on
TARGET business days (Monday to Friday except 1 January, Good Friday, Easter Monday, 1 May, 25 and 26 December),
around 16:00 CET/CEST, as EUR → currency values for about 30 currencies. Attribution: "Source: ECB".

| Feed | URL | Used by |
| --- | --- | --- |
| Daily | `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml` | `rates:sync` when the store is current |
| Last 90 days | `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml` | First install, gaps up to 90 days |
| Full history (1999-01-04 →) | `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml` | `rates:backfill` only (several MB) |

The files use the `http://www.ecb.int/vocabulary/2002-08-01/eurofxref` vocabulary (`Cube time` / `Cube currency rate`).
Values are kept as the published text. The full history carries withdrawn currencies (CYP, MTL, EEK…) and currencies
that stopped being published; availability is per day, never assumed from a static list.

## Frankfurter-compatible JSON API (key configurable, off by default)

A wire format for a self-hosted mirror of the same reference data:

- `GET {base}/latest?base=EUR` → `{"amount": 1, "base": "EUR", "date": "Y-m-d", "rates": {"USD": 1.0992, …}}`
- `GET {base}/{from}..{to}?base=EUR` → `{"amount": 1, "base": "EUR", "start_date": …, "end_date": …, "rates": {"Y-m-d": {…}}}`

`amount` must be 1 and `base` EUR (or `rebase: true`). JSON numbers become their shortest round-trip decimal string.
The label defaults to `ECB` (the data is ECB data); the storage key stays distinct (`mirror`).

## Manual rates (key `manual`)

Never fetches. With `conversion.source: manual` conversions use manual rates only (air-gapped installs).

## Outbound calls

Every call goes through OutboundUrlGuardBundle (public address pinned, cloud metadata blocked, private networks only
for a mirror that opts in), with no redirects, an idle timeout and a total duration, a decoded-byte cap per feed and
the User-Agent `nowo-currency-kit/<version> (+<outbound.contact_url>)`. No user or tenant data is ever sent.
