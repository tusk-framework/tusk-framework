# Database migrations

Tusk uses Doctrine Migrations for ordered, versioned schema changes. Migration
commands are available in a freshly generated application; they do not require
`tusk build`, load `bootstrap/app.php`, or run during application startup.

## Development workflow

```bash
php vendor/bin/tusk make:entity Billing/Invoice
php vendor/bin/tusk make:migration "Create invoices"
php vendor/bin/tusk migrate:status
php vendor/bin/tusk migrate --dry-run
php vendor/bin/tusk migrate
```

Review and commit the generated migration. Treat it as application code: inspect
the SQL and add data migration steps deliberately. `migrate:status` is read-only;
for SQLite it does not create a missing database file or migration-history
table. `make:migration` compares ORM mappings with the configured database and
reports when no schema change is detected.

## Deployment and SQL review

The `migrate` command applies pending versioned migrations. It no longer means
the unversioned SchemaTool synchronization that older Tusk versions exposed.
Production execution requires an explicit acknowledgement:

```bash
APP_ENV=production php vendor/bin/tusk migrate --allow-production
```

Review a dry run before execution, or export SQL to a file:

```bash
php vendor/bin/tusk migrate --dry-run
php vendor/bin/tusk migrate --write-sql=var/migrations.sql
```

Dry-run and SQL export do not apply migrations or initialize the history table.
Protect exported SQL files as deployment artifacts and review them before use.

## Rollback

Rollback always names the target version and requires `--allow-down`; in
production both acknowledgements are mandatory:

```bash
php vendor/bin/tusk migrate:rollback 0 --allow-down --dry-run
APP_ENV=production php vendor/bin/tusk migrate:rollback 0 --allow-down --allow-production
```

Rollback can be destructive and a migration may be irreversible. Review the
target and generated down SQL before applying it. Tusk never guesses that the
latest migration should be undone.

## Local schema synchronization

`schema:sync` is a development shortcut for direct SchemaTool synchronization,
not a deployment workflow. It requires `--force` to change the schema, supports
`--dry-run`, and is always rejected when `APP_ENV=production`:

```bash
php vendor/bin/tusk schema:sync --dry-run
php vendor/bin/tusk schema:sync --force
```

Commit versioned migrations instead of relying on this command to prepare
shared or production databases.

## Existing databases and explicit baselines

Tusk never assumes that an existing database matches a migration and never
baselines it automatically. Before adopting migrations:

1. Take and verify a restorable database backup.
2. Create a migration that accurately describes the existing schema baseline;
   review it against the real database and do not apply it to that database.
3. Use Doctrine's explicit version registration only after confirming that the
   schema is equivalent to the baseline migration. Tusk intentionally does not
   expose a baseline shortcut. Run a reviewed one-off script with the same
   project-root composition Tusk uses and Doctrine's `VersionCommand` (with the
   baseline migration FQCN and `--add`); do not use a separately configured
   Doctrine executable unless you have verified that it targets the exact same
   connection and metadata table.
4. Confirm `migrate:status` reports the baseline as applied, then deploy later
   migrations through the normal reviewed workflow.

The one-off operation initializes Doctrine's migration metadata table and
records the selected version; it does not execute that migration's `up()`.
Never mark a version applied merely to silence a pending status. The recorded
version asserts that the database already has that migration's effects.

## Configuration

Generated projects use `config/migrations.php` with the `App\Migrations`
namespace and `database/migrations` directory. Relative paths resolve from the
project root. The existing `DB_*` variables and `DB_ENTITY_PATHS` control the
single Doctrine connection and entity mapping; do not put credentials in the
migration configuration file.
