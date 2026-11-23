# Roadmap

The bundle is built slice by slice from [CONTRACT.md](CONTRACT.md) (section 22). Each slice is one pull request with
tests, docs and a green CI; 0.x tags per slice after their audit, **1.0.0** after Beacon adopts 0.1 / 0.2,
`bundle-req-audit` at 100 % of the applicable REQs and the REQ-SEC-004 audit.

## Slices

| Slice | Version | Content | Status |
| --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + `release-check`, CI matrix, PHPStan, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, Flex recipe, strict config tree | Merged (shipped with 0.1.0) |
| **S1** core | 0.1.0 | Domain and ISO 4217 table, storage, ECB and Frankfurter-compatible sources, guarded fetcher, console commands, Scheduler bridge, converter, manual rates (CLI), Twig filter, events, audit and HttpLog bridges, 7 locales | Released (v0.1.0; patch v0.1.1: `#[AsMessageHandler]`, transaction-doctrine `^1.8.1`, docs and tooling) |
| **S2** UI | 0.2.0 | UiKit + FormKit admin pages, house access pattern, change-log CSV, demos on UiKit, e2e | Not started (no UI code on `main`) |
| **Later** | 0.3.x+ | Per-tenant manual rates (QC-8), public read API `<prefix>/currency/v1/rates` (QC-12), more sources, non-EUR pivot | Not started |

Status as of 2026-11-23 (latest tag v0.1.1). There is no open pull request for a slice; the only open pull request is
#8 (`fix/req-gaps`, documentation for REQ-MSG-001).

## Road to 1.0.0

1.0.0 is tagged after Beacon adopts 0.1 / 0.2, `bundle-req-audit` at 100 % and the REQ-SEC-004 audit
([CONTRACT.md](CONTRACT.md) section 22, QC-13).

- **Slices left:** S2 (the UI, an immediate permissions audit). The "Later" row is not needed for 1.0.0. Distance:
  small to medium (one medium slice).
- **Open REQ gaps** (re-audit 2026-11-22, graded at v0.1.0): REQ-IDE-003 (the Cursor rule pack lacks
  `20-twig-and-public-assets.mdc`; add a short rule or record an exemption), REQ-DOCS-018 (GitHub About website and
  topics; a repository setting), REQ-MSG-001 (handler autoconfiguration released in 0.1.1; the retry and
  failure-transport docs are still missing on `main`).
- **Already in an open pull request:** #8 closes the documentation part of REQ-MSG-001.
- **REQ-SEC-004:** appendices VR and VR2 cover 0.1.0. No appendix covers v0.1.1 (a small change). S2 is an immediate
  audit (permissions), then the batch audit before 1.0.0.
- **Owner decisions still open:** none recorded. Decided: runtime-setting registration is deferred to 0.2 (audit VR
  answer Q3); instance-wide rates only in 0.x (QC-8, QB-4).
