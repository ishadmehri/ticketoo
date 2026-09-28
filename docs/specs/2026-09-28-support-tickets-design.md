# Design Spec — Support Tickets (WordPress Plugin)

- **Date:** 2026-09-28
- **Status:** Approved (design sections 1–6 confirmed by project owner)
- **Path:** `D:\programming\ticketoo\tiketoo`
- **Text domain / slug:** `support-tickets`

## 1. Intent and success criteria

A lightweight, fast, simple support-ticket plugin for WordPress, built from scratch, intended to be sold later.

- **Audience:** logged-in users **and** guests.
- **Interfaces:** shortcode + Gutenberg block + Elementor widget (all optional/soft), plus a custom support-agent panel inside wp-admin. Section interactions are AJAX (REST).
- **Base features:** create ticket, threaded conversation, file attachments, statuses + filter/search, assignment to agent, email notifications, close by user / auto-close.
- **Structure:** a single plugin with extension hooks for a future Pro version.
- **Targets:** WordPress 6.4+, PHP 8.0+.
- **Success:** installing the plugin gives working ticket flow with no build step, no runtime vendor dependencies, WordPress/PHP coding standards compliance, and translation/RTL readiness (Persian from day one).

**Out of scope for the base (YAGNI, added later via hooks):** categories/tags, priority, SLA, report dashboards, form builder, multi-step authentication.

## 2. Approach decision

Data storage options were compared:

| Option | Verdict |
|---|---|
| **A. Custom DB tables (chosen)** | Fast at scale, indexed queries, trivial guest support, clean AJAX panel |
| B. CPT + comments | Free admin UI but slow (postmeta joins), guests cannot be authors, poor fit for a custom fast panel |
| C. Hybrid (CPT + custom tables) | Two sources of truth, added complexity, no clear benefit — rejected |

**Decision: Option A.** See `docs/ADR/0001-use-custom-tables.md`.

**UI/tech stack:** vanilla JavaScript (no framework, no build step), WordPress REST API instead of `admin-ajax.php` (ADR 0002), progressive enhancement on the front end (ADR 0003), Gutenberg block built on `wp.*` globals without a bundler (ADR 0004), Elementor widget loaded only when Elementor is active.

## 3. File structure

```
support-tickets/
├── support-tickets.php          Entry point: header, constants, activation, includes
├── uninstall.php                Full cleanup on delete (tables, role, options)
├── readme.txt                   WordPress.org readme
├── phpcs.xml.dist               PHPCS + WPCS configuration (dev only)
├── composer.json                Dev tooling only: PHPCS, PHPUnit (no runtime vendor)
├── includes/
│   ├── Autoloader.php           Small PSR-4 style autoloader (no Composer at runtime)
│   ├── Plugin.php               Bootstrapping: hooks, service wiring
│   ├── Activator.php            Table creation (dbDelta), roles/caps
│   ├── Database/
│   │   ├── TicketRepository.php CRUD, search, filters, counts
│   │   └── MessageRepository.php
│   ├── Model/
│   │   ├── Ticket.php  Message.php  Attachment.php   (typed PHP 8 properties)
│   ├── Rest/
│   │   ├── FrontendController.php   create/list/read/reply (user + guest)
│   │   └── AdminController.php      panel endpoints (capability-gated)
│   ├── Shortcode/SupportTickets.php `[support_tickets view="list|form|ticket" id=""]`
│   ├── Guest/TokenAccess.php    guest link verification (hashed token)
│   ├── Email/Notifier.php       wp_mail wrapper + filterable templates
│   ├── AutoClose.php            daily WP-Cron sweep
│   └── Admin/
│       ├── MenuPage.php         custom panel page (shell + AJAX)
│       └── Capabilities.php     `st_manage_tickets` cap + `support_agent` role
├── assets/
│   ├── css/ frontend.css  admin.css       (minified, loaded only where needed)
│   └── js/  frontend.js  admin.js  block.js   (vanilla JS, no bundler)
├── templates/
│   ├── list.php  form.php  conversation.php  guest-notice.php  email/default.php
├── integrations/
│   ├── Gutenberg.php            three blocks, render → shortcode
│   └── Elementor.php            one widget with `view` control (soft dependency)
└── tests/                       PHPUnit
```

Notes:
- Every file has a single responsibility and stays short.
- Theme override: template files resolved via `st_template_path` filter (standard WP template hierarchy style).
- No Composer and no build step required for end users.

## 4. Data model

Three tables with `{$wpdb->prefix}`:

### `st_tickets`
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | displayed as `#123` |
| `subject` | VARCHAR(190) | FULLTEXT index for search |
| `status` | VARCHAR(20) + index | `open`, `pending`, `answered`, `closed` — strings, not ENUM, extendable via `st_statuses` |
| `user_id` | BIGINT UNSIGNED | `0` for guests |
| `email` | VARCHAR(160) | guest email + fallback for users |
| `assigned_to` | BIGINT UNSIGNED NULL | agent user id |
| `guest_token_hash` | CHAR(64) NULL | SHA-256 of token; raw token only in emailed link |
| `last_activity_at` | DATETIME | auto-close basis + sorting |
| `created_at` / `updated_at` | DATETIME | |

Indexes: `(status, last_activity_at)`, `user_id`, `email`, `assigned_to`, FULLTEXT(`subject`).

### `st_messages`
`id`, `ticket_id` (index), `user_id`, `email`, `is_agent` TINYINT(1) (`0` user/guest, `1` agent, `2` system message), `content` LONGTEXT + FULLTEXT, `created_at`.

### `st_attachments`
`id`, `message_id` (index), `file_path`, `original_name`, `mime`, `size`, `created_at`.

**Attachments:** stored under `uploads/support-tickets/YYYY/MM/` with a 24-char random filename; downloads only through a permission-checked endpoint (owner / agent / guest-with-token); `.htaccess` deny in the folder. Documented limitation: nginx needs a server-level rule (noted in readme).

**Capabilities:** custom cap `st_manage_tickets` (default: administrators only) + new role `support_agent` (that cap + `read`). Users see only their own tickets via `user_id`; guests only with valid `id + token`.

## 5. Front-end flows and REST contract

**One shortcode, three views:** `[support_tickets view="list|form|ticket" id="123"]`.
Gutenberg: three blocks whose `render_callback` delegates to the shortcode (single HTML/JS source). Elementor: one widget with `view` and `id` controls, registered only if Elementor is active.

**Progressive enhancement:** list, form and conversation are server-rendered (fast first paint, works without JS); vanilla JS then enhances with AJAX for filters/pagination, posting replies, closing tickets.

**REST — namespace `support-tickets/v1`**, all with `wp_rest` nonce:

| Method & route | Who | Purpose |
|---|---|---|
| `GET /tickets?status=&q=&page=&scope=mine\|all` | authenticated user / agent only | list + page counts |
| `POST /tickets` | anyone | create ticket + first message |
| `GET /tickets/{id}?token=` | owner / agent / guest+token | ticket + paged messages |
| `POST /tickets/{id}/messages` (multipart) | same | reply + `files[]` upload |
| `POST /tickets/{id}/status` | owner: `closed` only; agent: any | status change |
| `POST /tickets/{id}/assign` | `st_manage_tickets` | assign agent |
| `GET /attachments/{id}` | permission-checked | secure download |

**Guest flow:** form (name, email, subject, message) → ticket created → email with link `.../?st_ticket=ID&token=RAW` (only hash stored) → same link to view and reply. No email access means no return access (documented in readme).

**Guest access model (explicit):** a token grants access to **one specific ticket only**. Guests have no list endpoint and no session state; every guest route (`GET /tickets/{id}`, `POST /tickets/{id}/messages`, `GET /attachments/{id}`) requires the `token` parameter and verifies it with `hash_equals` against `guest_token_hash`. Listing requires an authenticated user or agent.

**User flow:** log in → own ticket list → create / reply / close.

## 6. Custom wp-admin panel

- Top-level menu `support-tickets` (capability `st_manage_tickets`), submenu **Settings**.
- Page = static shell + AJAX data (no `WP_List_Table`): status tabs, search box, agent filter, new-ticket counter, paginated ticket table (number, subject, customer, agent, status, last activity).
- Detail view opens in-page: conversation (user left / support right), reply box with attachments, status buttons, assign select, attachment download links.
- Plugin CSS/JS enqueued only on this screen. Counter refreshed on load and then every 60 seconds (interval option `st_counter_refresh_seconds`, `0` disables polling).
- Reuses the same REST endpoints with `scope=all` — one code path.
- **Settings (base):** auto-close days (0 = off), attachment max size/types, sender name/address, enable/disable guest ticket creation.

## 7. Email notifications and auto-close

**Emails (all via `wp_mail`, filterable):**

| Event | Recipients | Content |
|---|---|---|
| New ticket | all `st_manage_tickets` holders | number, subject, excerpt, direct link |
| Agent reply | ticket owner's email | view/reply link (token link for guests) |
| User/guest reply | assigned agent (or all agents if unassigned) | direct link |
| Auto-closed | owner | "closed — reply to reopen" |

Template `templates/email/default.php` with `{{vars}}`; filters `st_email_subject`, `st_email_body`, `st_email_headers`; action `st_before_send_email`; guard against sending to the replier themselves; `st_defer_email` hook for future queueing.

**Auto-close (WP-Cron):**
- Daily event `st_auto_close_sweep`, registered on activation and re-checked with `wp_next_scheduled`.
- Query: `status IN ('open','pending')` and `last_activity_at < NOW() - N days`.
- Before closing: `st_auto_close_ticket` filter (veto allowed) → status `closed` + system message (`is_agent=2`) in thread + email to owner.
- `st_auto_close_days` option, default 14, `0` disables. New activity updates `last_activity_at` and restarts the window.
- Documented caveat: WP-Cron runs on visits; real cron recommended in readme.

**Close by user:** close button for the owner (while open); agents can reopen. Action `st_ticket_status_changed` after every change.

## 8. Security

- Explicit `permission_callback` on every route.
- `wp_rest` nonce + server-side `current_user_can('st_manage_tickets')` for `scope=all`; ownership check (`user_id`) or `hash_equals` on `guest_token_hash` otherwise.
- Input: `sanitize_text_field`, `sanitize_email`, `wp_kses_post` for message bodies; output: `esc_html` / `esc_url` everywhere.
- Uploads: `wp_check_filetype_and_ext`, size/type limits from settings, random filename, outside the media library, `.htaccess` deny, `realpath` guard against path traversal on download.
- All SQL through `$wpdb->prepare`; no `eval`, no user-controlled includes.

## 9. Standards and quality

- **WPCS** enforced by PHP_CodeSniffer (`phpcs.xml.dist`); prefixes `st_` / `st-`.
- PHP 8.0: typed properties, `declare(strict_types=1)` in new files.
- i18n: text domain `support-tickets`, all strings translatable, RTL handled with `wp_style_add_data(..., 'rtl', 'replace')`.
- DocBlocks on every public class/method; hooks documented with `@since` / `@param`.
- **Tests:** PHPUnit covering repositories, guest token verification, auto-close, capabilities, and REST permission cases (guest with wrong token → 403; user cannot read another user's ticket). Commands documented in `docs/development.md`.

## 10. Extension points (future Pro)

Filters: `st_statuses`, `st_ticket_fields`, `st_rest_response_ticket`, `st_template_path`, `st_email_*`.
Actions: `st_ticket_created`, `st_ticket_status_changed`, `st_ticket_assigned`, `st_auto_close_ticket`, `st_before_send_email`.

## 11. Documentation and GitHub

```
docs/
├── README.md            project overview
├── development.md       environment setup, commands, standards, workflow
├── specs/               this document and future specs
├── ADR/
│   ├── 0001-use-custom-tables.md
│   ├── 0002-rest-api-over-admin-ajax.md
│   ├── 0003-progressive-enhancement-frontend.md
│   └── 0004-no-build-step-vanilla-js.md
└── PHASES.md            execution phases
```

- Repository: GitHub via `gh`, first commit with the spec, standard commit messages.
- `readme.txt` (WordPress.org format) + `CHANGELOG.md`.

## 12. Phases (summary; details in `docs/PHASES.md`)

1. **Phase 0 — scaffolding:** repo, folder structure, bootstrap file, autoloader, PHPCS config, CI-ready composer scripts.
2. **Phase 1 — core:** tables, models, repositories, guest tokens, REST endpoints with permission tests.
3. **Phase 2 — front end:** shortcodes, templates, vanilla JS enhancement, Gutenberg block, Elementor widget.
4. **Phase 3 — admin panel:** menu page, panel JS/CSS, assignment/status actions, settings screen.
5. **Phase 4 — email & cron:** Notifier, templates, auto-close sweep, close-by-user.
6. **Phase 5 — quality & release:** full test pass, WPCS clean, i18n/RTL check, readme.txt, first GitHub release.
