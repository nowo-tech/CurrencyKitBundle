# Contributing

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [Development setup](#development-setup)
- [Code style](#code-style)
- [Tests](#tests)
- [Pull requests](#pull-requests)
- [Git hooks (REQ-GIT-001)](#git-hooks-req-git-001)

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are
expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Development setup

1. Clone the repository.
2. Start the dev container: `make up` then `make install`.
3. Run the checks: `make test`, `make test-coverage-100`, `make test-with-db` (MySQL 8.4, MariaDB 11.4 and
   PostgreSQL 16 services, profile `db`), `make cs-check`, `make phpstan`, `make validate-phpdoc`, `make igor`,
   `make validate-translations`.
4. Pre-release: `make release-check`.

## Code style

- PHP-CS-Fixer (PSR-12 + Symfony): `make cs-fix` / `make cs-check`.
- PHPStan level max with the `nowo-tech/phpstan-frankenphp` rulesets (classic, worker-strict, hardening).
- PHPDoc and Markdown docs in **English**. Strict types in every PHP file.
- No float anywhere on the money path: amounts and rates are decimal strings.

## Tests

- Never call the network: sources are tested with the recorded files of `tests/Fixtures` through `MockHttpClient`
  (see `tests/Fixtures/README.md`), and DNS goes through a fake lookup.
- `tests/Integration/Database` runs on SQLite by default and on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 with
  `NOWO_TEST_DATABASE_URL` (`make test-with-db`); the two-connection lock tests run only on a server.
- Coverage of `src/` must stay at 100 % (`make test-coverage-100`).
- When the mapping changes, regenerate the reference DDL: `CYK_PRINT_DDL=1 vendor/bin/phpunit --filter
  testReferenceDdlInUpgradingIsCurrent` prints it; paste it into `docs/UPGRADING.md` and `docs/INSTALLATION.md`.

## Pull requests

- Target the default branch with a conventional-commit title.
- Ensure `make release-check` passes.
- Keep `docs/CHANGELOG.md` (`[Unreleased]`) and the docs updated when behaviour or configuration changes.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers, nor `Co-Authored-By: Claude …` /
`noreply@anthropic.com` ones, to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```
