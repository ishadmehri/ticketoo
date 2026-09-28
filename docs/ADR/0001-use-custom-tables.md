# ADR 0001: Use custom database tables

- **Status:** Accepted (2026-09-28)
- **Context:** The plugin must be simple, light and fast, with a custom AJAX support panel, guest tickets (no user account) and predictable performance at scale.

## Options considered

1. **Custom tables** — dedicated `ticketoo_tickets`, `ticketoo_messages`, `ticketoo_attachments`.
2. **CPT + comments** — free admin UI, search and media library; guests cannot be post authors, postmeta joins degrade at scale, a custom fast panel fights the default list tables.
3. **Hybrid (CPT for tickets + custom tables for messages)** — two sources of truth, sync complexity, no clear benefit.

## Decision

Option 1: custom tables with indexed queries and FULLTEXT search.

## Consequences

- We own CRUD, capability checks, search, and uninstall cleanup (written once).
- Guests are a first-class case (`user_id = 0` + email + hashed token).
- Media library integration is not used; attachments are handled by a dedicated, permission-checked endpoint.
- Future Pro features can add their own tables or columns without touching post storage.
