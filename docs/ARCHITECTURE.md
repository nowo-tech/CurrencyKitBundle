# Architecture

```
src/Domain/            Money, CurrencyCode (ISO 4217 table), RateDate, ConvertedAmount, enums, ports, events, exceptions
src/Application/       converter, resolver, synchronizer, batch validator, manual rates, reader, maintenance, calendar
src/Infrastructure/Dbal/          DBAL store, lease, write runner (transaction-doctrine), connection-only registry
src/Infrastructure/Doctrine/      read-only entities, schema listener (prefix, NO PAD collations), schema checker
src/Infrastructure/Source/        Ecb\*, Frankfurter\*, Manual\*, Csv\*, file reader
src/Infrastructure/Http/          GuardedHttpFetcher (the only HTTP code)
src/Infrastructure/Cache/         PSR-6 shared cache of resolved lookups
src/Infrastructure/Symfony/       DI, configuration tree, commands, Messenger handler, Twig, security, access events
src/Bridge/Scheduler/             schedule provider and cold-start trigger
src/Resources/                    XML mapping, translations (10 locales)
```

## Rules (tests/Architecture)

- `Domain` imports only its own classes and PHP core types; `brick/math` is used inside `Domain\Money\Decimal` only
  and never appears in a signature.
- `Application` depends on `Domain`, its own classes and PSR interfaces (clock, log, event dispatcher) only.
- Nothing in `src/` calls `persist()`, `flush()`, `clear()` or `resetManager()`; the change-log table has no update or
  delete path.

## Both host kinds (REQ-ARCH-001)

| Topic | Standard Symfony host | DDD / hexagonal host |
| --- | --- | --- |
| API | Inject `CurrencyConverterInterface`; Twig filter | Same; value objects only; wrap `ConvertedAmount` in your own type |
| Storage | Default manager's connection; attribute or XML mapping; may query the entities read-only | `doctrine.entity_manager: <own manager>`, XML mapping; read through `ReferenceRateReaderInterface` |
| Flush | The bundle never flushes (DBAL writes) | Same |
| Events | Symfony dispatcher, after the commit | Same; map them to your bus |
| Access | `security.*_roles` | Own `CurrencyKitAccessCheckerInterface` and `*_ACCESS_CHECK` listeners |

The test suite runs the same flows on both (standard: default manager + attributes; DDD: a second connection and
manager + XML mapping + a custom checker).

## Writes

Every write is a transaction-doctrine write unit around one DBAL transaction on the configured manager's connection
(see [CONCURRENCY.md](CONCURRENCY.md)). The write unit is given a registry that holds that connection and no entity
manager, so a replay rolls back the connection only and never clears, resets or flushes any entity manager.
