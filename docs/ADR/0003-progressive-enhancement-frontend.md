# ADR 0003: Progressive enhancement on the front end

- **Status:** Accepted (2026-09-28)
- **Context:** Sections must feel fast (AJAX), but the ticket pages are also the plugin's public interface.

## Options considered

1. **Server-rendered HTML, JS enhances** — first paint is fast, content exists without JS, then filters/pagination/replies go through REST.
2. **Client-rendered SPA-style** — slicker transitions, but blank pages without JS, more code, slower first paint.

## Decision

Option 1: shortcodes render list/form/conversation server-side; vanilla JS intercepts and sends AJAX requests for interactive parts.

## Consequences

- Works for no-JS environments and crawlers; fewer failure modes.
- Slightly more code than pure SPA (form fallback handling), accepted for robustness.
