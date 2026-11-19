# PSR compliance

Package: `nowo-tech/currency-kit-bundle` (`symfony-bundle`)

| PSR | Status | Notes |
| --- | --- | --- |
| PSR-1 / PSR-12 Coding style | **Adopted** | PHP-CS-Fixer (`@PSR12` + `@Symfony`), `make cs-check` in CI |
| PSR-4 Autoloading | **Adopted** | `Nowo\CurrencyKitBundle\` maps to `src/` |
| PSR-3 Logger | **Adopted** | Static messages with structured context; no amounts tied to a person, no request data |
| PSR-6 Cache | **Adopted** | Optional shared cache of resolved lookups (`cache.pool`), versioned |
| PSR-14 Event dispatcher | **Adopted** | Domain events are plain classes dispatched after the commit (ids and value objects only) |
| PSR-20 Clock | **Adopted** | "Today" in the source calendar and every timestamp read an injected clock (`MockClock` in tests) |
| PSR-7 / PSR-15 / PSR-17 / PSR-18 | **N/A** | Symfony HttpClient is used directly behind the bundle's `OutboundFetcherInterface` port |
