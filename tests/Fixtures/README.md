# Recorded fixtures

Recorded on 2026-11-19 (about 21:20 Europe/Madrid, after that day's publication). Tests never call the network: the
files are served through `MockHttpClient`, and a test fails if a real HTTP client is built.

| File | Recorded from | Notes |
| --- | --- | --- |
| `ecb/eurofxref-daily.xml` | `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml` | As served (2026-11-19, 29 currencies) |
| `ecb/eurofxref-hist-90d-full.xml` | `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml` | As served (64 days, 2026-08-24 … 2026-11-19) |
| `ecb/eurofxref-hist-90d.xml` | same | Trimmed to the 10 newest days (2026-11-06 … 2026-11-19) |
| `ecb/eurofxref-hist-sample.xml` | `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml` | 9 days: 1999-01-04/05 (first days, historic CYP), 2007-12-28/31 (last CYP and MTL days), 2008-01-02 (after the euro changeover), 2022-03-01/02 (RUB present, then missing), 2026-11-12/13 |
| `frankfurter/latest.json` | `GET /v1/latest?base=EUR` of the public Frankfurter-compatible API | As served |
| `frankfurter/date-2026-11-13.json` | `GET /v1/2026-11-13?base=EUR` | As served |
| `frankfurter/range-2026-11-10..2026-11-14.json` | `GET /v1/2026-11-10..2026-11-14?base=EUR` | As served (4 days) |

Hostile inputs (billion laughs, external entity, DOCTYPE, deep nesting, huge attribute, truncated file, compressed
bomb, duplicate dates, negative / zero / exponent values) are built inline by the tests.
