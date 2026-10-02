# Ticketoo

Lightweight, fast support-ticket plugin for WordPress: tickets for logged-in
users and guests, threaded conversations with attachments, an agent panel in
wp-admin, email notifications and daily auto-close ΓÇö all on custom tables with
a REST API (`ticketoo/v1`) and no external services.

## At a glance

- **Front end:** `[ticketoo]` shortcode (list / form / conversation views),
  Gutenberg blocks, optional Elementor widget; server-rendered first, enhanced
  by vanilla JS (no build step).
- **Guests:** create tickets without an account; a bearer token delivered by
  email grants later access to the conversation.
- **Agents:** dedicated wp-admin panel ΓÇö filter, assign, reply, change status.
- **Email:** notifications for every event (new ticket, replies, auto-close).
- **Auto-close:** daily WP-Cron sweep closes stale tickets (configurable
  window, `0` disables), with an opt-out filter per ticket.
- **Data:** custom tables (`wp_ticketoo_tickets`, `wp_ticketoo_messages`,
  `wp_ticketoo_attachments`) with `dbDelta()` schema managed by the activator.

## Requirements

WordPress ΓëÑ 6.4, PHP ΓëÑ 8.0. Development requires Docker and Node.js ΓÇö see
[development.md](development.md).

## Documentation

- [Design spec](specs/2026-09-28-support-tickets-design.md) ΓÇö the source of
  truth for behaviour and REST contracts.
- [Implementation plan](plans/2026-09-28-ticketoo.md) ΓÇö task breakdown used to
  build the plugin.
- [ADRs](ADR/) ΓÇö architectural decisions (custom tables, REST over admin-ajax,
  progressive enhancement, no build step).
- [development.md](development.md) ΓÇö setup, test/lint commands, coding
  standards, REST route table, smoke-test record.
- [PHASES.md](PHASES.md) ΓÇö phase ΓåÆ task status matrix.
- `readme.txt` and `CHANGELOG.md` in the repository root ΓÇö WordPress.org
  readme and changelog for releases.

## License

GPL-2.0-or-later.
