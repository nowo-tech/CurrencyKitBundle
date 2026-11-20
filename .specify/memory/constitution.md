# CurrencyKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behaviour lives in `docs/CONTRACT.md` (design contract), `specs/001-baseline/spec.md`,
`docs/SPEC-DRIVEN-DEVELOPMENT.md` and the integrator docs (`USAGE.md`, `CONFIGURATION.md`).

### II. Spec-first, test-proven
PHPUnit and PHPStan are the mechanical proof. Behavioural changes require tests.

### III. 100% code inventory traceability
Every production file under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec
updates in the same PR.

### IV. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### V. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges.

### VI. Standard by default, DDD opt-in
Works out of the box in a standard Symfony project; usable from a strict DDD / hexagonal host (framework-free Domain,
interfaces and value objects as public contracts). The bundle writes through DBAL on the configured connection and
never flushes, clears or resets the host's entity manager; events are dispatched after commit and carry ids.

### VII. Worker-safe, honest, exact
FrankenPHP worker mode with the kernel kept alive and not reset: services hold configuration only, the request memo is
cleared at every boundary. Money is a decimal string, never a float. Every result says it is a reference rate. Writes
are transaction-doctrine write units that survive contention (REQ-RUNTIME-003). Administration is denied by default.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-11-20 | **Last Amended**: 2026-11-20
