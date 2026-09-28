# ADR 0002: REST API over admin-ajax.php

- **Status:** Accepted (2026-09-28)
- **Context:** All section interactions must be AJAX, for the wp-admin panel and the front end alike.

## Options considered

1. **REST API (`/wp-json/ticketoo/v1`)** — single namespace, standard `wp_rest` nonce, JSON error handling, one code path for panel and front end.
2. **`admin-ajax.php`** — classic, but action-name soup, inconsistent response shapes, duplicated permission handling.

## Decision

REST API for everything, with explicit `permission_callback` per route and one nonce.

## Consequences

- Panel and front end share the same controllers (`scope=all` vs `scope=mine`).
- Guests authenticate per ticket by `token` parameter instead of a nonce-bound session.
- Endpoint contracts are documented in the spec and covered by tests.
