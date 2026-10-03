# Development

## Prerequisites

- **Docker** — `@wordpress/env` runs WordPress, MySQL and WP-CLI in containers.
- **Node.js** (with npm) — drives the env and gate scripts.
- **PHP is not required on the host** — PHPUnit and PHPCS run inside the
  container (`vendor/bin/...`), installed by Composer there.

## Setup

```sh
npm install            # @wordpress/env and scripts
npm run env:start      # build + start the WordPress containers
npm run composer -- install   # PHPCS (WPCS) + PHPUnit polyfills in-container
```

The plugin is bind-mounted from the repository root into
`wp-content/plugins/ticketoo`, so edits on the host are live in the container.
Site: <http://localhost:8888> (admin / `password`).

Other env commands: `npm run env:stop`, `npm run env:down` (remove containers).

## Tests and lint

```sh
npm run phpunit        # PHPUnit suite (104 tests) — wp-env run cli ... phpunit
npm run phpcs          # WordPress-Coding-Standards — wp-env run cli ... phpcs
node --check assets/js/frontend.js   # syntax check for shipped JS
```

### Upload-directory hygiene (run before phpunit)

The attachment tests assert that the plugin upload directory contains no
stored files; leftovers from manual smoke runs fail two tests. Clean it first:

```sh
npm run env:start
npx wp-env run cli sh -c "find /var/www/html/wp-content/uploads/ticketoo -mindepth 1 ! -name '.*' -exec rm -rf {} +"
```

Dotfiles such as the plugin's `.htaccess` (deny-all rules) are kept — the test
helper `stored_filenames()` skips them by design.

> **Warning — never run `wp plugin uninstall ticketoo` in the dev
> environment.** The plugin's directory *is* the repository root (bind
> mount), and WP-CLI's uninstall deletes that directory's contents. Use
> `wp plugin deactivate ticketoo` for a clean state; run the activator
> (`wp plugin activate ticketoo`) to recreate tables and the cron event.

## Coding standards

- `phpcs.xml.dist` enforces the full **WordPress (WPCS)** standard on
  `ticketoo.php`, `includes/`, `templates/` and `integrations/` (24 files).
- Text domain `ticketoo` is enforced for every translated string
  (`esc_html__`, `esc_attr__`, `__`, ...).
- Class files use PSR-4-style names (`Autoloader.php`, `Plugin.php`, ...), so
  the `WordPress.Files.FileName` rule is excluded for `includes/` and
  `integrations/` (the plugin ships its own autoloader — no Composer at
  runtime).
- `assets/js/*` is excluded from PHPCS: it is plain ES5-style vanilla JS with
  no build step (ADR 0004) and is syntax-checked with `node --check`.
- JS/CSS style follows WordPress conventions: tabs for indentation,
  `ticketoo-` prefixed handles and CSS namespaces, REST calls authenticated
  with a `wp_rest` nonce (`X-WP-Nonce`) plus same-origin cookies.
- Stylesheets stay RTL-ready by using CSS logical properties only (no
  `-rtl.css` mirrors are shipped); `tests/test-rtl-css.php` fails the suite
  if a physical `left`/`right` declaration appears in either file.

## REST route table

Base: `/wp-json/ticketoo/v1/` (also reachable as
`/index.php?rest_route=/ticketoo/v1/...`).

| Route | Method | Permission | Purpose |
| --- | --- | --- | --- |
| `/tickets` | GET | agent (CAP) | List tickets; `status` query arg filters |
| `/tickets` | POST | public (guests toggle in settings) | Create ticket (`subject`, `content`, optional `name`, `email`) |
| `/tickets/{id}` | GET | owner, agent, or guest `token` | Read one ticket with messages |
| `/tickets/{id}/messages` | POST | owner, agent, or guest `token` | Post a reply (`content`) |
| `/tickets/{id}/status` | POST | owner: `closed` only; agent: any | Change status (`status` slug; guests receive 403) |
| `/tickets/{id}/assign` | POST | agent (CAP) | Assign to a user / unassign |
| `/attachments/{id}` | GET | owner, agent, or guest `token` | Permission-checked attachment download |

Notes:

- Guest access uses the per-ticket bearer `token` (query arg or body field);
  tokens are compared with `hash_equals` and never serialized back to
  responses or logs.
- Response bodies never contain `guest_token`.
- A non-agent reply to a `closed` or `answered` ticket moves it to `open`
  (`ticket_status` in the reply payload; `pending` stays agent-curated);
  agent replies do not change the status.
- The full contract (payloads, status codes, error codes) is pinned by
  `tests/test-rest-*.php` and specified in the
  [design spec](specs/2026-09-28-support-tickets-design.md).

## Shortcode

- `[ticketoo]` — ticket **list** (auth required; guests see a notice).
- `[ticketoo view="form"]` — new-ticket form (works without JS).
- `[ticketoo view="ticket" id="N"]` — single conversation.
- Deep links use `?ticketoo_ticket=N` (+ `&token=...` for guests) and are
  built from `home_url()`, so put a shortcode page in front of your site
  (the dev env sets page 4 as the static front page) for email links to land
  on the conversation.

## Smoke test

Recorded on 2026-10-02 against <http://localhost:8888> after the working tree
was reconstructed (see task-15 report). Dev-env setup for this run: page 4
(`[ticketoo view="form"]`) set as static front page, theme Twenty
Twenty-Five, plugin activated.

| # | Step | Result |
| --- | --- | --- |
| 1 | Front page renders, `#ticketoo-form` present | **PASS** — `GET /` 200 (68 KB), form markup server-rendered |
| 2 | Guest creates ticket (`POST /tickets`) | **PASS** — `201 Created`, `{"id":1,...}` |
| 3 | Email captured in logs | **PASS** — `ticketoo-smoke-mail.log`: admin "New ticket #1" + guest confirmation with token link |
| 4 | Guest opens token link | **PASS** — `200`, conversation + reply form rendered |
| 5 | Agent replies from wp-admin panel (cookie + `wp_rest` nonce) | **PASS** — `201`, `is_agent:1`; "Support replied" email with token link captured |
| 6 | Guest attempts status change | **PASS (expected 403)** — `ticketoo_rest_forbidden`: "Guests cannot change the ticket status." (spec: owner-only) |
| 7 | Logged-in owner creates and closes a ticket | **PASS** — create `201` (owner attributed), `POST /status` `200` `{"status":"closed"}` |
| 8 | Auto-close sweep (`wp cron event run ticketoo_auto_close_sweep`) | **PASS** — 16-day-stale ticket → `closed`, `last_activity_at` reset, "Ticket #1 has been closed" email captured |
| 9 | Uninstall live-run (tables dropped, cron unscheduled, options deleted) | **PASS** — verified then plugin reactivated (see warning above about `wp plugin uninstall` in dev) |

Mail delivery itself uses `sendmail` inside the container (not configured);
the `smoke-mail-log` mu-plugin captures every `wp_mail()` payload, which is
what the table verifies.

## Quality gates (0.1.0)

Run for every release; all must be green:

1. `npm run phpunit` → `OK (104 tests, 516 assertions)`
2. `npm run phpcs` → `24 / 24 (100%)` — 0 errors, 0 warnings
3. `node --check` on `assets/js/*.js` → 3/3 clean
4. `wp i18n make-pot . languages/ticketoo.pot --slug=ticketoo --domain=ticketoo`
   regenerated and committed whenever translatable strings change.
