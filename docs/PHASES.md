# Phases

Mapping of the design spec's Phase 0–5 to the implementation plan's Tasks
1–15, with status. Source: [spec § Implementation phases](specs/2026-09-28-support-tickets-design.md)
and [plan](plans/2026-09-28-ticketoo.md).

| Phase | Scope (spec) | Tasks | Status |
| --- | --- | --- | --- |
| **0 — Scaffolding** | Repo, folder structure, bootstrap file, autoloader, PHPCS config, CI-ready composer scripts | Task 1 — Dev environment + plugin skeleton | Complete (commit `3c89256`, review Approved) |
| **1 — Core** | Tables, models, repositories, guest tokens, REST endpoints with permission tests | Task 2 — Tables/Activator/capabilities; Task 3 — Ticket model + repository; Task 4 — MessageRepository + guest tokens; Task 5 — REST create/list/read/reply; Task 6 — REST status/assign + attachments | Complete (Tasks 2–6, all reviews Approved) |
| **2 — Front end** | Shortcodes, templates, vanilla JS enhancement, Gutenberg block, Elementor widget | Task 7 — Shortcodes/templates/server rendering; Task 8 — Frontend JS (progressive enhancement); Task 9 — Gutenberg block + Elementor widget | Complete (Tasks 7–9, all reviews Approved) |
| **3 — Admin panel** | Menu page, panel JS/CSS, assignment/status actions, settings screen | Task 10 — Admin menu + settings page; Task 11 — Admin panel JS | Complete (Tasks 10–11, all reviews Approved) |
| **4 — Email & cron** | Notifier, templates, auto-close sweep, close-by-user | Task 12 — Email notifications; Task 13 — Auto-close cron + close-by-user | Complete (Tasks 12–13, all reviews Approved) |
| **5 — Quality & release** | Full test pass, WPCS clean, i18n/RTL check, readme.txt, first GitHub release | Task 14 — i18n/uninstall/readme/changelog; Task 15 — Quality gate + docs + release | Complete — Task 14 review Approved; Task 15 gates green (phpunit 97/471, phpcs 24/24, `node --check` 3/3), docs written. **Release mechanics:** `v0.1.0` tag and `git push` are deferred by owner instruction. |

## Release checklist (Task 15)

- [x] `npm run phpunit` — `OK (97 tests, 471 assertions)`
- [x] `npm run phpcs` — `24 / 24 (100%)`, 0 errors 0 warnings
- [x] `node --check` on shipped JS — 3/3 clean
- [x] `docs/development.md` (prerequisites, commands, standards, REST routes,
  smoke record), `docs/PHASES.md`, `docs/README.md`
- [x] Manual smoke on <http://localhost:8888> — 9/9 steps, see
  [development.md § Smoke test](development.md#smoke-test)
- [x] `git commit -m "chore: quality gate, docs for 0.1.0"`
- [ ] `git tag v0.1.0` — **deferred** (owner: no tags)
- [ ] `git push origin main --tags` — **deferred** (owner: no push)
