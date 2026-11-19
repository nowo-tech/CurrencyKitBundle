# Concurrency (REQ-RUNTIME-003)

## Write paths

| Write path | Unit | Control | Conflict answer |
| --- | --- | --- | --- |
| Store one day (sync, backfill, import, Messenger) | 1 per day | Insert-or-ignore + `SELECT … FOR UPDATE` of the publication row, unique `(source, rate_date, currency)` | Replay ×3; final ⇒ `ConcurrencyConflict` (`CONFLICT_001`, console exit 1); `SyncInterruptedException` → `CONFLICT_002` when an earlier day of the run committed |
| Run lease | 1 statement | Conditional `UPDATE … WHERE locked_until < now` (no transaction held while fetching) | Held ⇒ `skipped` with the seconds left (console exit 0) |
| Manual rate create | 1 | `FOR UPDATE` of the currency's manual rates (by id), overlap check, insert | Overlap ⇒ `CURRENCY_009`; lock ⇒ `CONFLICT_001` |
| Manual rate update / end | 1 | `UPDATE … WHERE id = ? AND version = ?` (+ the overlap lock when the window moves) | Stale ⇒ `CURRENCY_008`; lock ⇒ `CONFLICT_001` |
| Apply a kept change | 1 | `FOR UPDATE` of the publication row; no-op when the stored value already equals the kept one | Already applied ⇒ no-op |
| Prune | batches of 1 000 days | One unit per batch | Lock ⇒ replay; final ⇒ exit 1 |
| Forget an actor | 1 | Two `UPDATE … SET … = NULL` | Lock ⇒ replay |

## Rules applied

- Units: `nowo-tech/transaction-doctrine` `WriteUnit` around `DoctrineTransactionRunner::forConnection()` on the
  configured manager's connection. Replays happen for deadlocks (1213, 40P01), lock-wait timeouts (1205, 55P03) and
  serialization failures (40001, MariaDB 1020), 3 attempts with full jitter. Unique violations and lost connections
  are not conflicts: they propagate unchanged.
- The unit's registry holds the connection and no entity manager: a replay rolls back the connection's leftover levels
  and never clears, resets or flushes an entity manager (the host's included).
- Inside a host transaction the unit runs once, as a nested level (savepoint with DBAL 4 or savepoints enabled); a
  failure rolls back to it and propagates.
- No HTTP, file or mail I/O inside a unit: feeds are fetched and validated before any write; files are read one day at
  a time between units. Events, audit lines and cache invalidation run after each commit.
- Locks in a fixed order: the publication row, then the day's rate rows; manual rates by id. READ COMMITTED assumed.
- Replays hold nothing from an earlier attempt: rows are re-read and the actor is re-resolved on every attempt.
- With transaction-doctrine's bundle installed, its `CommitLedger` is passed to the unit: an attempt that already
  committed is not replayed (`committed: true`).
- A run that stored some days and then fails throws `SyncInterruptedException` (the days stay stored, each is complete
  on its own; the next run continues).

## Tests

`tests/Integration/Database/ConcurrencyTest.php` uses two connections on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16
(the CI "Databases" job): two writers of the same day (one waits, times out, replays, gives up with
`ConcurrencyConflict`; then `Unchanged`, no duplicate), the manual-rate overlap race, committed-then-conflict
(`CONFLICT_002`), a host transaction (nested level), and the connection still usable afterwards.

## Residual risk

- The lease statement runs outside a unit; on the 0.2 web path a lease claim is a commit for transaction-doctrine's
  request watermark, so a later conflict in the same request answers `CONFLICT_002` (the safe side).
- DBAL 3 without `setNestTransactionsWithSavepoints(true)`: inside a host transaction a failure marks the whole host
  transaction rollback-only (DBAL's emulated nesting).
