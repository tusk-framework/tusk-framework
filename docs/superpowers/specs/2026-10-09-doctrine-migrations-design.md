# Versioned Doctrine Migrations Design

## Goal

Give generated Tusk applications a safe, reviewable, repeatable workflow for database schema changes. Doctrine Migrations owns version tracking and execution; Tusk owns project defaults, first-party CLI commands, production safeguards, and documentation.

## Context and constraints

- The Framework currently requires PHP `^8.2`, Doctrine ORM `^3.6`, and Symfony Console `^7.0`.
- `Tusk\Cli\Commands\MigrateCommand` currently calls ORM `SchemaTool::updateSchema()` directly. It has no migration history and can change a schema without a reviewable migration file.
- `EntityManagerFactory` discovers attribute-mapped entities under `src/Domain` by default and reads database connection settings from environment variables.
- Generated projects currently have no migrations directory or migration configuration. Their SQLite database default is under `database/database.sqlite`.
- `bin/tusk` compiles application services, routes, and commands from the project source tree; it does not scan installed Framework code under `vendor`, and generated applications must not need a prior `tusk build` just to access Framework-owned CLI commands.
- Framework-owned commands and application-defined commands have distinct ownership: the Framework provides its own explicit command catalog, while application commands continue to use the existing compiled command registry.
- The Engine forwards non-built-in commands to the installed Framework CLI. This design adds no Engine, RoadRunner, or worker-lifecycle responsibilities.
- No existing Tusk project should be assumed to have a migration history. The old `migrate` behavior must not silently be treated as equivalent to versioned migrations.

## Chosen approach

Use the standalone `doctrine/migrations` library, not a custom migration engine or the Symfony bundle. Require the compatible `^3.9` line. The Framework repository is a library and does not commit `composer.lock`; generated applications lock the resolved release in their own lock file, and CI verifies dependency resolution. Doctrine remains responsible for migration discovery, version storage, schema diffs, execution, and SQL generation. Tusk supplies a small set of Symfony Console adapters and generated-project defaults.

This dependency line requires PHP `^8.1`, DBAL `^3.6 || ^4`, Symfony Console `^5.4 || ^6 || ^7 || ^8`, and excludes ORM 4; its ORM diff integration is compatible with ORM 3. These constraints match the Framework's current PHP, Console, and ORM ranges. Recheck this compatibility against the actual Composer solver during implementation before changing dependency files.

## Generated-project configuration

The project generator creates `database/` and `database/migrations/`, and writes `config/migrations.php`. The generated configuration returns a plain PHP array with:

- migration namespace `App\Migrations` and path `database/migrations`;
- the migration metadata table name, using Doctrine's standard `doctrine_migration_versions` default;
- the project's existing Doctrine connection and ORM entity metadata as inputs to the migration dependency factory.

The Framework resolves the path against the generated project's base directory, not the process's incidental current directory. Configuration is loaded without executing migrations. Existing `DB_*` and `DB_ENTITY_PATHS` environment settings remain the source of connection and mapping configuration; secrets are never copied into generated configuration or command output.

Migration classes are ordinary, reviewable PHP files committed with the application. ORM-based generation compares the live development database with the mapped entity schema; it does not run automatically when an entity is created or when the app starts.

## First-party command contract

The Framework exposes these project commands through its normal CLI; Engine forwarding remains unchanged. They are registered explicitly in a Framework-owned command catalog, independent of the generated application's compiled command registry. A fresh generated project must be able to list and invoke Framework commands through `vendor/bin/tusk` without first running `tusk build`. Application-defined commands remain discoverable through the current compiled registry and do not need to be moved into the Framework catalog.

The CLI combines the Framework catalog with the application's compiled commands when the latter are available. Framework command names are reserved: a compiled application command with the same name must fail with a clear registration error instead of silently replacing or shadowing either command. CLI startup must not scan `vendor` source files or require the application to compile its source tree before Framework commands are available.

| Command | Contract |
| --- | --- |
| `make:migration [description]` | Generate a Doctrine migration from the difference between the connected database schema and current ORM mappings. Write it beneath `database/migrations`; report a clear no-difference result without creating an empty file. Developers review and edit generated SQL before committing it. |
| `migrate:status` | Read-only report of applied and pending versions. It must not create a SQLite database file, create/synchronize/modify the metadata table, or otherwise write to the database. If the database or migration history is not initialized, report that state clearly without initializing it as a side effect. |
| `migrate [--dry-run] [--write-sql=PATH]` | Apply pending migrations in ascending version order only. A successful execution is recorded by Doctrine and a repeated invocation does not reapply it. Dry-run and SQL export never apply migrations or advance history. |
| `migrate:rollback <target-version> --allow-down` | Explicitly migrate down to the named target version. The acknowledgement is required in every environment; production additionally requires `--allow-production`. It never means “undo the latest” implicitly, and it never deletes migration files or history entries. Doctrine's irreversible-migration behavior is preserved and surfaced as a non-zero failure. |
| `schema:sync [--dry-run] [--force]` | Preserve the former SchemaTool convenience only for local development. It is never a substitute for versioned migrations, is rejected when `APP_ENV=production`, and requires `--force` to execute schema changes. Dry-run prints the proposed SQL without applying it. |

`migrate` changes meaning from the current unversioned SchemaTool operation to the conventional versioned operation. The old operation moves to the explicit `schema:sync` name; there is no compatibility alias that could continue to make `migrate` ambiguous. Update `make:entity` guidance, help text, generated-project docs, and release notes to teach the new flow.

## Production and failure safety

- Applying migrations in `APP_ENV=production` requires the explicit `--allow-production` option. This applies to interactive and non-interactive execution so deployment automation is deliberate and auditable.
- Rolling back always requires `--allow-down`; production rollback requires both `--allow-down` and `--allow-production`. A rollback always names its target version.
- `schema:sync` always refuses production, including when `--force` is supplied. It is a development convenience, not a deployment mechanism.
- Doctrine migrations can contain arbitrary PHP and data-manipulation SQL, so Tusk must not claim it can reliably infer whether every migration is destructive. Generated files require human review; production execution is explicit; dry-run and SQL export are available for review.
- Command errors return non-zero exit codes. User-facing errors identify the failed operation without printing connection URLs, usernames, passwords, environment values, stack traces, or raw driver diagnostics. Detailed exceptions may be sent only to an explicitly configured, access-controlled logger with secrets redacted.
- Doctrine's own transaction and platform behavior is preserved. Tusk does not promise atomic rollback for databases or migrations that do not support it.

## CLI bootstrap and ownership

The CLI establishes the generated project's base directory and environment, then registers Framework-owned commands from its explicit catalog. Migration command dependencies are resolved lazily from the project's existing Doctrine configuration and environment, then used to construct Doctrine Migrations' `DependencyFactory`. The migration CLI path must not depend on the application's compiled container, compiled command registry, or loading `bootstrap/app.php`; it must also avoid eagerly connecting to or creating the database for commands that do not need a connection. In particular, `migrate:status` must preserve the read-only behavior defined above.

Application-defined commands continue to be loaded from the project's compiled command registry when present. Their existing build-and-register lifecycle is unchanged. The Framework catalog must not become a replacement registry for application commands.

Tusk does not shell out to `vendor/bin/doctrine-migrations`, scan arbitrary vendor source, create a second migration registry, or add a second ORM abstraction. The Engine remains a forwarding layer and adds no migration registration or execution logic.

The CLI command classes stay thin: option validation and Tusk-specific safety gates live in the adapters; migration algorithms, metadata storage, SQL generation, and migration class loading remain Doctrine's responsibility. The Engine continues to own process/runtime orchestration and simply forwards these Framework commands.

## Existing databases and transition

No existing schema or database is automatically baselined. A database previously changed with the old `migrate` command has no trustworthy migration history; the new command must not infer that its schema corresponds to any migration. Document the deliberate adoption procedure: inspect and back up the database, compare its schema with the intended baseline, then use Doctrine's explicit version-registration mechanism only after verification. Fresh generated applications start with an empty migration history and use versioned migrations from their first schema change.

The behavior change is intentional because preserving the old `migrate` behavior under that name would undermine the safety contract. Release notes must call out that `schema:sync` is local-only and that existing databases need an explicit, reviewed baseline before versioned migrations are applied.

## Scope boundaries

- No custom migration engine, second ORM, Symfony Doctrine bundle, or database abstraction layer.
- No migration execution during project generation, application bootstrap, worker startup, or deploy startup.
- No automatic baseline, automatic rollback, automatic data migration, or inferred destructive-operation classifier.
- No Engine command-dispatch redesign, RoadRunner integration, runtime health check, or worker lifecycle change.
- No support for arbitrary multiple EntityManagers/connections in the first slice; generated defaults use the existing single Doctrine connection.
- No requirement to compile application commands before listing or invoking Framework-owned CLI commands; this does not remove the existing compilation requirement for application-defined commands.

## Verification criteria

- Composer resolves `doctrine/migrations:^3.9` with the Framework's PHP, ORM, DBAL, and Console constraints; generated applications lock the resolved release.
- Generated-project integration tests verify migration configuration, namespace/path resolution from outside the project working directory, and the default metadata table name.
- A freshly generated skeleton with installed dependencies can run `vendor/bin/tusk list`, `vendor/bin/tusk migrate:status`, and `vendor/bin/tusk make:migration` without a preceding `tusk build`; these tests also verify Framework commands are registered independently of application commands and that command-name collisions fail clearly.
- Existing application-defined commands remain available through the compiled registry after `tusk build`; Framework catalog registration neither replaces nor shadows them.
- Framework CLI integration tests exercise the installed/generated-project path, not only the Framework monorepo where source scanning can accidentally expose Framework commands.
- SQLite integration tests cover: empty/nonexistent database and no migrations; pending status without creating a SQLite file or metadata table; generated pending migration; successful apply and recorded version; repeated apply without duplicate execution; dry-run/SQL export without schema or history changes; failing migration with non-zero exit and no credential disclosure; and rollback to an explicit target with the required safeguards.
- Tests prove production `migrate` refuses without `--allow-production`, production rollback additionally refuses without `--allow-down`, and `schema:sync` refuses production even with `--force`.
- Irreversible migration failures are reported without pretending that rollback succeeded.
- `make:entity`, CLI help, generated skeleton documentation, README, and deployment guidance consistently distinguish `make:migration`, `migrate`, `migrate:status`, `migrate:rollback`, and local-only `schema:sync`.
- Existing Doctrine integration tests remain valid; no migration work is added to Engine or RoadRunner process lifecycle.
- The Engine smoke/integration coverage confirms its existing forwarding path reaches Framework commands without adding migration-specific Engine behavior.

## Official references

- [Doctrine Migrations 3.9 introduction and installation](https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/introduction.html)
- [Doctrine Migrations 3.9 migration generation](https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/generating-migrations.html)
- [Doctrine Migrations 3.9 status, dry-run, SQL export, execution, and rollback](https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/managing-migrations.html)
- [Doctrine Migrations 3.9 Composer compatibility constraints](https://raw.githubusercontent.com/doctrine/migrations/3.9.x/composer.json)
