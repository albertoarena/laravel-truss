# CLAUDE.md — Instructions for Claude Code

## Project Overview

**Package:** `albertoarena/laravel-truss`
**Type:** Laravel Composer package
**Purpose:** A live database structure viewer. Scans migrations, builds a cached schema snapshot, and renders it as a scrollable, zoomable ER diagram inside the app. Structure only, no data is ever exposed.
**License:** MIT

## Stack

- PHP 8.2+ (lowered from 8.3 in v1.10, matching what Laravel 12 requires; see `docs/adr/0001-php-82-minimum.md`)
- Laravel 12+
- Pest for testing
- Laravel native schema introspection (`Schema::getTables/getColumns/getIndexes/getForeignKeys`) — no Doctrine DBAL
- Mermaid.js for diagram rendering (no build step)
- `spatie/laravel-package-tools` for package scaffolding

## Commands

- `composer test` — run the Pest suite
- `composer lint` — Laravel Pint (code style check)
- `composer lint:fix` — fix Pint issues automatically
- `npm test` — Vitest unit tests for the client-side diagram logic (`resources/js`)
- `npx playwright test` — browser tests for the dashboard (`tests/e2e`)
- `npm run test:a11y` — accessibility specs only (keyboard operation + an axe-core WCAG scan); also its own CI step
- `php artisan truss:show` — print the database structure as a terminal table (structure only)
- `php artisan truss:open` — open the Truss dashboard in the browser
- `php artisan truss:rebuild` — manually rebuild the cached schema snapshot
- `php artisan truss:export` — export the structure (dbml/json/csv/markdown/mermaid) for CI and tooling; `--check` fails on drift

Frontend assets (JS/CSS + a vendored Mermaid) are served from the package via a gated `{prefix}/assets/{file}` route — no `vendor:publish`, no CDN. Set `TRUSS_MERMAID_URL` to load Mermaid from a CDN instead.

## Conventions (always true)

- **TDD is mandatory, and it is an ordering rule, not just a coverage rule.** For every change (feature, fix, refactor), in this order: (1) write the test, (2) run it and watch it fail for the right reason (red), (3) write the implementation to make it pass (green). Writing production code before its test is a violation *even if* a passing test is added right afterwards: the test must exist and fail first. Never commit implementation without a corresponding test. No controller, command, class, or function is written before the test that drives it. PHP uses Pest; client-side code under `resources/js` uses Vitest for pure logic and Playwright for rendering/interaction.
- **No data exposed, ever.** Only table, column, index, and foreign key structure. Never row contents. This is the package's core promise, treat it as a hard constraint, not a config default. The boundary is the `CREATE TABLE` definition vs. table rows: column defaults count as structure and are in scope (see `docs/DECISIONS.md`).
- **Introspection stays pure.** Code under `src/Introspection/` must have zero knowledge of HTTP, Blade, or Mermaid. It only builds and returns a schema representation. The detailed layer rules live in `.claude/rules/introspection.md`, which loads automatically when you touch that layer or its tests.
- **Config is the single source of truth** for excluded tables, route path, cache TTL, per-connection settings, diagram styling, focus depth, the large-schema warning threshold, the route middleware stack, and the default viewer allow-list (`authorization.allowed_emails`). Don't hardcode any of these. Authorization is a fixed `viewTruss` gate — the ability *name* is not configurable, and the gate callback is always the app's to override. The allow-list only feeds the *default* gate; it is not a renamable ability. The gate is consulted only in non-local environments (local is open), and a denial returns 404. See `docs/DECISIONS.md` → *Authorization: production-gated model*.
- **Git commits:** `type: short subject` (max 50 chars), then a body paragraph explaining what and why, not how. Never include "Generated with Claude Code" or "Co-Authored-By: Claude". Use a heredoc for multi-line commit messages.
- **Docs stay in sync.** Any change to commands, config, or user-facing behavior must be reflected in `README.md` and `docs/` in the same change. The public docs site is a separate repo, `albertoarena/laravel-truss-docs` (published at trussphp.com); it tracks this package and must be updated there too, but it reads from the latest package **release**, so its update lands when the next release ships and the docs site rebuilds.
- **Roadmap check on every release.** A release is not done until the public roadmap reflects it. When a release ships, review `src/data/roadmap.ts` in the `laravel-truss-docs` repo and move whatever the release delivered into Shipped with its version. A partially delivered item is split: the shipped part moves to Shipped, the remainder stays in Approved next or Exploring (e.g. schema doctor Phase 1 shipped in v1.5.0 while the later phases stayed on the roadmap).

- **Homebrew tap check, on any release that attaches a PHAR.** The tap is `albertoarena/homebrew-truss`, and its one formula points at a `truss.phar` release asset in this repository, pinned by version and SHA-256. **CI bumps that formula, so the release check is that CI did it**, never that someone remembered to. A tap still naming the previous version installs the old binary and `brew upgrade` reports nothing to do, so it fails silently in both directions and is worse than having no tap. **Does not apply until a release actually attaches a PHAR**, which has not happened yet.

Frontend-specific conventions (keeping the live demo aligned, the no-build/no-CDN asset rule, and where the Mermaid generator lives) are in `.claude/rules/frontend.md`, which loads automatically when you touch `resources/`.

## Pointers

- Architecture and domain model: `docs/DESIGN.md`
- Phased build plan: `docs/INSTRUCTIONS.md`
- Decision log: `docs/DECISIONS.md`
- Path-scoped rules (auto-load when matching files are touched): `.claude/rules/` (`introspection.md`, `frontend.md`)

This file should stay short enough to read in under a minute. If you're about to add detail, it probably belongs in `docs/` instead, with a pointer added here.
