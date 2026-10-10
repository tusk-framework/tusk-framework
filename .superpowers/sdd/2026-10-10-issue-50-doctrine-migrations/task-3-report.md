# Task 3 report: Project-root Doctrine migration context

## Status

Implemented and committed on `feature/issue-50-doctrine-migrations`. The temporary `composer.lock` created for verification remains untracked/ignored and was not included in the commit.

## RED / GREEN evidence

- Initial PHPUnit invocation found a syntax error in the new test helper; corrected it before considering test behavior.
- Meaningful RED: `vendor/bin/phpunit tusk-cli/tests/Doctrine/MigrationConfigurationTest.php --testdox --filter ExistingEntityManagerFactoryUsesGeneratedProjectPathsInsteadOfCurrentWorkingDirectory` failed an assertion. From another working directory, the existing factory chose that directory's `src/Domain` rather than a generated project's mapping path. That probe confirmed why the new migration context must receive and anchor to the project root.
- With Doctrine dependencies installed, the first focused factory run exposed a test call to protected DBAL 4 `Connection::connect()`. Replaced it with public connection-state assertions; no production change was needed.
- Added `testDependencyFactoryUsesGeneratedNestedTableStorageName`; it passed and confirmed the resulting Doctrine `DependencyFactory` uses the configured table name while remaining disconnected.
- Initial focused suite passed, with the documented platform skip below; controller follow-up enabled SQLite per process.

## Implementation

- `tusk-cli/src/Doctrine/MigrationConfiguration.php` loads the project's optional `.env` using Tusk's `Env` precedence, validates migration configuration, anchors absent/relative migration paths at the project root, and applies existing DB_* defaults and DB_ENTITY_PATHS behavior.
- It translates generated `storage.table_storage` into Doctrine Migrations 3.9's top-level `table_storage`, preserving generated-project configuration while passing valid values to Doctrine.
- `tusk-cli/src/Doctrine/MigrationDependencyFactoryFactory.php` composes `DependencyFactory::fromEntityManager()` with `ConfigurationArray`. Its local `EntityManagerLoader` defers ORM/DBAL object construction until Doctrine asks for the EntityManager. The DBAL connection remains unopened until an operation connects or queries.
- `tusk-cli/tests/Doctrine/MigrationConfigurationTest.php` covers project-root paths, defaults, redacted actionable malformed-config errors, environment parity, lazy/no-file construction, and the effective Doctrine metadata storage table name.

## Verification

- Focused: `vendor/bin/phpunit tusk-cli/tests/Doctrine/MigrationConfigurationTest.php tusk-data/tests/Integration/DoctrineIntegrationTest.php --testdox`
  - Passed: 9 tests, 35 assertions, 1 skipped.
  - `DoctrineIntegrationTest` is skipped because this PHP build has no PDO drivers (`PDO::getAvailableDrivers()` returned an empty array), so `pdo_sqlite` is unavailable.
- Full suite: `vendor/bin/phpunit --testdox`
  - Passed: 597 tests, 2,790 assertions, 4 skipped.
- `php -l` passed for both production files and the new test file.
- `git diff --check` passed.

## Controller follow-up verification

The PHP package contains `php_pdo_sqlite.dll` and `php_sqlite3.dll`, but php.ini leaves both disabled. I reran the tests with per-process PHP flags (no php.ini edit):

```text
php -d "extension_dir=<PHP 8.5 ext directory>" -d extension=php_pdo_sqlite.dll -d extension=php_sqlite3.dll vendor/bin/phpunit tusk-cli/tests/Doctrine/MigrationConfigurationTest.php tusk-data/tests/Integration/DoctrineIntegrationTest.php --testdox
```

- Focused result: 9 tests, 38 assertions, no skips. Doctrine ORM persistence/retrieval passed against SQLite, and the migration factory's metadata table and disconnected/no-file guarantees passed.
- Full suite with the same per-process extension flags: 598 tests, 2,802 assertions, 1 skipped.
- No php.ini changes were made. Actual migration apply/status/rollback behavior remains for Task 4's SQLite command integration tests.

## Independent review follow-up

- The first independent review identified that Doctrine's documented top-level `table_storage` settings were ignored and the lazy ORM loader did not honor `APP_ENV` loaded from `.env` through Tusk's `Env` helper.
- Both findings were fixed: Doctrine's top-level shape now takes precedence over the generated nested compatibility shape, and ORM dev mode uses the same `Env::get('APP_ENV')` semantics as `EntityManagerFactory`.
- Regression coverage added for custom table/column names and production mode supplied only by `.env`. Reviewer confirmed both findings resolved with no new Critical/Important findings.
- Configuration follow-up tests passed: 10 tests, 38 assertions; integration + migration configuration + command contract tests passed: 17 tests, 94 assertions.
- The configuration now exposes the resolved SQLite path so CLI status/preview commands can avoid connecting to a nonexistent SQLite file (and therefore avoid implicitly creating it).

## Doctrine configuration ruling

Doctrine Migrations 3.9's official reference and installed 3.9.8 source confirm that PHP configuration uses top-level `table_storage`. Tusk's generator currently emits `storage.table_storage`, so the migration configuration boundary translates the existing generated shape instead of changing the generator. `ConfigurationArray` consumes the normalized values and avoids reloading relative paths from the process CWD. The dependency factory uses the documented ORM `fromEntityManager` composition.

References:

- https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/configuration.html
- https://github.com/doctrine/migrations/blob/3.9.x/src/Configuration/Migration/ConfigurationArray.php
- https://github.com/doctrine/migrations/blob/3.9.x/src/DependencyFactory.php

## Self-review and concerns

- Existing `EntityManagerFactory` was left unchanged, preserving its no-argument DI factory behavior.
- No `bootstrap/app.php`, compiled container, or compiled command registry coupling was introduced.
- User-facing configuration failures do not expose exception text, DB URLs, credentials, or env values.
- SQLite non-creation is asserted after config construction, dependency factory construction, EntityManager resolution, and connection retrieval. PDO_SQLite was enabled per process for controller verification; full migration command queries remain for Task 4.
- Existing unrelated untracked `.phpunit.cache/` and `docs/superpowers/plans/2026-10-10-issue-50-doctrine-migrations.md` were preserved.

## Commit

Initial implementation: `6016f10`. Review-cycle corrections are included in the follow-up Task 3 commit.
