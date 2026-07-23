# Contributing

Thanks for considering a contribution.

## Workflow

1. Fork the repository and create a branch from `main`.
2. Write a failing test first, then make it pass. Every change is test driven.
3. Keep the public API stable. Anything in `src/` that is not marked `@internal` is a published contract.
4. Keep the three documentation surfaces in sync (`README.md`, `docs/`, and `website/`) whenever you change commands, config, or user-facing behaviour.
5. Run the checks below before opening a pull request.

## Checks

```bash
composer test        # Pest (PHP)
composer lint        # Laravel Pint, code style
npm test             # Vitest, client-side diagram logic
npx playwright test  # Playwright, browser rendering and interaction
```

The PHP suite uses an in-memory SQLite database and Orchestra Testbench, so no external services are needed.

## Conventions

- Strict types in every PHP file.
- The introspection layer (`src/Introspection/`) has zero knowledge of HTTP, Blade, caching, or Mermaid. It takes a connection in and returns a schema representation out.
- Structure only. Truss never reads or exposes row data. Treat this as a hard constraint.
- One behaviour per test, with descriptive names.
- Commit messages as `type: short subject`, then a body explaining what and why.
- Do not use em dashes in prose.

## Reporting bugs

Open an issue with a minimal reproduction. A failing test is the most helpful form a report can take. For security issues, please email arena.alberto@gmail.com instead of opening a public issue.
