# FrankenPHP worker audit (`resetKernel = false`)

Audited revision: 0.1.0 (pre-release, `[Unreleased]`).

| Area | Finding | Status |
| --- | --- | --- |
| Services | `final readonly` services hold configuration only (settings, table names, URLs, limits) | ✅ |
| Request memo | `RateMemo` (public lookups only, never per person), bounded to 2 048 entries, cleared on `kernel.request`, `kernel.finish_request`, `kernel.terminate`, `console.terminate`, `WorkerMessageHandledEvent`, `kernel.reset`, and after every commit that writes rates or manual rates | ✅ |
| Shared cache | Optional PSR-6 pool, versioned keys: a manual rate reaches every worker at once; TTL ≤ 300 s | ✅ |
| ISO table | A class constant (immutable, opcache) | ✅ |
| HTTP | The injected client is stateless; no response, stream or handle survives a call; the guard's DNS child processes are per call | ✅ |
| Locale | Read from `RequestStack` per Twig call; `NumberFormatter` created per call | ✅ |
| Actor and user | Read from the token storage per call (and per write-unit attempt) | ✅ |
| Lease | Released in `finally`; a crashed holder's lease expires after the TTL | ✅ |
| Entity managers | Never flushed, cleared or reset by the bundle; writes go through DBAL | ✅ |
| Scheduler | `ColdStartTrigger` keeps one flag on the schedule's own trigger object, not on a container service | ✅ (documented) |
| Access events | Mutable flags live on per-decision event objects | ✅ |

Tests: `KernelWiringTest::testWorkerModeRequestsSeeAManualRateAtOnce` (one kernel, 400 requests in two locales, flat
memory, a manual rate created midway is seen by the next conversion), `ApplicationUnitTest::testMemo`, Igor and the
`phpstan-frankenphp` worker-strict rules in CI.
