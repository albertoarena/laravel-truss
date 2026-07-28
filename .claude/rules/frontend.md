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
- **The live demo lives in a separate repo and is release-gated.** The docs site (`albertoarena/laravel-truss-docs`, trussphp.com) runs this shipped frontend in its `/demo/` page. It fetches `resources/` from the package's **latest release tag** at build time, so a change here does not reach the live demo until a new release ships and the docs site rebuilds (preview unreleased frontend there with `PACKAGE_REF=main`). There is no demo page in this repo to edit. When a frontend change needs the demo's standalone page or sample schema updated, make that change in the docs repo, not here.
- **No build step, no CDN.** Frontend assets (JS/CSS, vendored Mermaid, fonts) are served from the package via the gated `{prefix}/assets/{file}` route. Keep them plain, self-contained, and CSP-safe (`script-src 'self'`); do not introduce a bundler or a CDN dependency. `TRUSS_MERMAID_URL` is the only opt-in to an external Mermaid.
