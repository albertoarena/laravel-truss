# CLAUDE.md — Instructions for Claude Code

## Project Overview

**Package:** `albertoarena/laravel-truss`
**Type:** Laravel Composer package
**Purpose:** A Telescope-style live database structure viewer. Scans migrations, builds a cached schema snapshot, and renders it as a scrollable, zoomable ER diagram inside the app. Structure only, no data is ever exposed.
**License:** MIT

## Stack

- PHP 8.3+
- Laravel 12+
- Pest for testing
- Laravel native schema introspection (`Schema::getTables/getColumns/getIndexes/getForeignKeys`) — no Doctrine DBAL
- Mermaid.js for diagram rendering (no build step)
- `spatie/laravel-package-tools` for package scaffolding
- Astro + Starlight for the documentation website

## Commands

- `composer test` — run the Pest suite
- `composer lint` — Laravel Pint (code style check)
- `composer lint:fix` — fix Pint issues automatically
- `php artisan truss:rebuild` — manually rebuild the cached schema snapshot
- `cd website && npm run dev` — run the docs site locally

## Conventions (always true)

- **TDD is mandatory.** Write a failing Pest test first, then implement. Never commit implementation code without a corresponding test. Applies to every change: features, fixes, refactors.
- **No data exposed, ever.** Only table, column, index, and foreign key structure. Never row contents. This is the package's core promise, treat it as a hard constraint, not a config default. The boundary is the `CREATE TABLE` definition vs. table rows: column defaults count as structure and are in scope (see `docs/DECISIONS.md`).
- **Introspection stays pure.** Code under `src/Introspection/` must have zero knowledge of HTTP, Blade, or Mermaid. It only builds and returns a schema representation. See `src/Introspection/CLAUDE.md` for the rules that apply there.
- **Config is the single source of truth** for excluded tables, route path, cache TTL, per-connection settings, diagram styling, focus depth, and the large-schema warning threshold. Don't hardcode any of these. Authorization is a fixed `viewTruss` gate the host app defines — the ability *name* is not configurable.
- **Git commits:** `type: short subject` (max 50 chars), then a body paragraph explaining what and why, not how. Never include "Generated with Claude Code" or "Co-Authored-By: Claude". Use a heredoc for multi-line commit messages.
- **Docs stay in sync.** Any change to commands, config, or user-facing behavior must be reflected in `README.md`, `docs/`, and `website/src/content/docs/` in the same change.

## Pointers

- Architecture and domain model: `docs/DESIGN.md`
- Phased build plan: `docs/INSTRUCTIONS.md`
- Decision log: `docs/DECISIONS.md`
- Introspection-layer rules: `src/Introspection/CLAUDE.md`

This file should stay short enough to read in under a minute. If you're about to add detail, it probably belongs in `docs/` instead, with a pointer added here.
