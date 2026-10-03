# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-10-01

### Added

- Custom-table ticket data model (tickets, messages, attachments) with guest
  bearer tokens and capability-gated access.
- REST API (`ticketoo/v1`) for creating, listing, reading, replying to and
  status-changing tickets, plus permission-checked attachment downloads.
- `[ticketoo]` shortcode with server-rendered list, form and conversation
  views, classic no-JS form handling, and progressive-enhancement front-end
  JavaScript.
- Gutenberg blocks (Ticketoo Ticket List, Ticketoo New Ticket Form, Ticketoo
  Conversation) and an optional Elementor widget wrapping the shortcode.
- wp-admin ticket panel (list, filter, assignment, status changes) and a
  settings screen: auto-close window, attachment size/type limits, sender
  name/email, guest tickets toggle, live counter refresh interval.
- Email notifications for every ticket event: new ticket to agents, guest
  confirmation with bearer link, replies to owner/agents, auto-close notice;
  filterable subjects, bodies, headers and deferred sending.
- Daily `ticketoo_auto_close_sweep` WP-Cron event closing inactive tickets,
  with veto filter and system message.
- i18n bootstrap: `ticketoo` text domain loaded from `languages/` on `init`,
  generated `languages/ticketoo.pot`.
- Uninstall routine dropping plugin tables, role, capability, options and the
  scheduled cron event; `readme.txt` and this changelog.
- Reply-to-reopen: a customer reply moves a closed or answered ticket back
  to `open` and fires `ticketoo_ticket_status_changed`; the reply payload
  carries `ticket_status` so the conversation badge updates live.
- RTL-ready stylesheets through CSS logical properties only, guarded by
  `tests/test-rtl-css.php`; no separate `-rtl.css` mirrors are shipped.

[0.1.0]: https://github.com/ishadmehri/ticketoo/releases/tag/v0.1.0
