# ADR 0004: No build step, vanilla JavaScript

- **Status:** Accepted (2026-09-28)
- **Context:** The plugin will be sold; buyers must upload it and it must work immediately, with a simple and light UI.

## Options considered

1. **Vanilla JS + `wp.*` globals, no bundler** — zero build, smallest payload, Gutenberg block registered via `wp.blocks`/`wp.element` directly.
2. **React/Vue + webpack/vite build** — richer UI tooling, but a build pipeline, node_modules, and release artifacts to maintain.

## Decision

Option 1. No build step anywhere; assets are plain files (optionally minified at release time only). No runtime Composer vendor directory either.

## Consequences

- UI code must stay disciplined: small modules per screen (`frontend.js`, `admin.js`, `block.js`).
- Elementor/Gutenberg integrations load only when their host is active (soft dependencies).
- Contributors need only PHP + WordPress knowledge to work on the plugin.
