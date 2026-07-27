---
paths:
  - "resources/js/**"
  - "resources/css/**"
  - "resources/views/**"
---

# Frontend rules

Applies when touching the shipped dashboard frontend: the `resources/js` modules,
`resources/css/truss.css`, or the Blade shell. Loaded on top of the root
`CLAUDE.md`.

- **The Mermaid definition generator is client-side JavaScript** (`resources/js/mermaid-definition.js`), not PHP. The interactive filter, focus, and label pipeline runs in the browser with no refetch, so generation must live there too. Pure logic is unit-tested with Vitest; rendering and interaction are covered by Playwright.
- **Keep the live demo aligned.** The docs live demo (`/laravel-truss/demo/`) runs the shipped frontend, copied from `resources/` at build time by `website/scripts/copy-demo-assets.mjs`. Whenever you change the frontend, verify the demo still works and update its standalone page or sample schema (`website/public/demo/`) if the change needs it.
- **No build step, no CDN.** Frontend assets (JS/CSS, vendored Mermaid, fonts) are served from the package via the gated `{prefix}/assets/{file}` route. Keep them plain, self-contained, and CSP-safe (`script-src 'self'`); do not introduce a bundler or a CDN dependency. `TRUSS_MERMAID_URL` is the only opt-in to an external Mermaid.
