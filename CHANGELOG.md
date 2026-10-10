# Changelog

## Unreleased

### Changed

- `tusk migrate` now applies pending versioned Doctrine migrations instead of
  performing direct SchemaTool synchronization. The local-only SchemaTool
  convenience is `tusk schema:sync --force`; production use is prohibited.
- Generated projects include migration configuration, a migrations directory,
  and database workflow guidance. Existing databases are not baselined
  automatically; production migration and rollback require explicit
  acknowledgements.
- Framework commands, including `migrate:status` and `make:migration`, can be
  used before `tusk build`. Compiled application commands remain available
  after building and cannot override reserved Framework command names.
