<?php

declare(strict_types=1);

// Configuration for albertoarena/laravel-truss.
// This is the single source of truth for Truss's behaviour. Authorization is a
// fixed `viewTruss` gate the host app defines — the ability name is not set here.

return [

    /*
    |--------------------------------------------------------------------------
    | Route prefix
    |--------------------------------------------------------------------------
    |
    | URL prefix under which the index page and the JSON schema endpoint are
    | registered, e.g. "truss" → GET /truss and GET /truss/api/schema.
    |
    */

    'route_prefix' => env('TRUSS_ROUTE_PREFIX', 'truss'),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Global on/off switch. Defaults to enabled only in the local environment.
    | Authorization is enforced separately by the fixed `viewTruss` gate.
    |
    */

    'enabled' => env('TRUSS_ENABLED', env('APP_ENV', 'production') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | The middleware stack applied to both Truss routes. Its job is to establish
    | the auth context (session, cookies, the authenticated user) so the
    | `viewTruss` gate can identify who is viewing — without it, the gate sees no
    | user and denies everyone in non-local environments. The default `web` group
    | covers session-based auth; swap it for a custom guard/Sanctum stack if your
    | app authenticates differently.
    |
    | The fixed `viewTruss` authorization check is always appended after this and
    | cannot be configured away — this list controls the auth *context*, not
    | whether authorization runs.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Truss is gated by the fixed `viewTruss` gate (the ability name is not
    | configurable). In non-local environments the shipped default gate admits
    | only the emails listed here — the zero-code path for "let these admins in".
    | Set them via TRUSS_ALLOWED_EMAILS as a comma-separated list, e.g.
    | TRUSS_ALLOWED_EMAILS="ada@example.com,grace@example.com".
    |
    | The list is ignored in local (the gate is not consulted there) and ignored
    | entirely if the host app defines its own `viewTruss` gate (e.g. a role
    | check). An empty list fails closed: no one may view in non-local until you
    | either add emails here or override the gate.
    |
    */

    'authorization' => [
        'allowed_emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSS_ALLOWED_EMAILS', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | The schema snapshot is derived, disposable data cached via Laravel's Cache
    | facade, keyed per connection. `ttl` is in seconds.
    |
    */

    'cache' => [
        'ttl' => (int) env('TRUSS_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Which database connections are visualizable, and any per-connection
    | overrides. When left empty, Truss uses the application's default
    | connection (config('database.default')).
    |
    | Example:
    |   'mysql' => ['excluded_tables' => ['legacy_import']],
    |
    */

    'connections' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded tables
    |--------------------------------------------------------------------------
    |
    | Tables hidden from the diagram by default (framework/infrastructure noise).
    | Applied server-side: excluded tables never appear in the API response.
    |
    */

    'excluded_tables' => [
        'migrations',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagram
    |--------------------------------------------------------------------------
    |
    | Styling options passed through to the Mermaid theme, plus the default
    | column-type label mode:
    |   'native'  → the full DB type (varchar(255), bigint unsigned) [default]
    |   'laravel' → a best-effort Laravel-style short label (string, integer)
    | The mode is user-toggleable in the UI; this is only the default.
    |
    */

    'diagram' => [
        'type_labels' => env('TRUSS_TYPE_LABELS', 'native'),

        // Where the browser loads Mermaid from. Null (the default) self-hosts it
        // from the package's own asset route — no CDN, so a strict CSP needs only
        // `script-src 'self'`. Set a URL (e.g. a CDN or your own copy) to opt out
        // of self-hosting: TRUSS_MERMAID_URL=https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js
        'mermaid_url' => env('TRUSS_MERMAID_URL'),

        // Lower bound for the automatic fit-to-screen: a large schema is never
        // auto-zoomed below this (it stays legible and you pan). The "Fit" button
        // ignores this and frames the whole diagram. 1.0 = 100%.
        'min_zoom' => (float) env('TRUSS_MIN_ZOOM', 0.7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme
    |--------------------------------------------------------------------------
    |
    | Truss ships a light and dark "blueprint" theme. To match the app Truss is
    | embedded in, redefine its colours and fonts here. Everything is optional:
    | only the knobs you set are overridden, the rest stay on the default, so a
    | handful of values re-skins the whole dashboard (chrome and diagram) in both
    | light and dark. Config driven, no build step.
    |
    | It is delivered as a same-origin stylesheet, so a strict CSP still needs
    | only style-src 'self' (no inline styles). Each value is validated before it
    | is emitted; an invalid value is ignored and falls back to the default.
    |
    | Colours accept hex, rgb()/rgba()/hsl()/hsla(), or a CSS colour keyword.
    | Fonts are family names only: name a font your app already loads or a system
    | font (Truss serves no font files here). Set a knob under both 'light' and
    | 'dark' to theme both modes; omit 'dark' to theme light only.
    |
    | Colour knobs and what each paints:
    |   accent            primary accent: headings, PK badges, entity borders, focus ring
    |   accent-secondary  secondary accent
    |   background        the canvas / page background (and relationship-label backdrop)
    |   surface           panels, table bodies, rows, and inputs
    |   surface-alt       row striping
    |   text              body and diagram text
    |   muted             secondary text and the relationship lines / labels
    |   border            table, panel, and field lines
    |
    */

    'theme' => [
        'fonts' => [
            'mono' => env('TRUSS_THEME_FONT_MONO'),
            'sans' => env('TRUSS_THEME_FONT_SANS'),
        ],

        'colors' => [
            'light' => [
                // 'accent' => '#3730a3',
                // 'background' => '#ffffff',
            ],
            'dark' => [
                // 'accent' => '#a5b4fc',
                // 'background' => '#0b1020',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Focus
    |--------------------------------------------------------------------------
    |
    | Focus mode reduces the diagram to a table and its foreign-key neighbours.
    | `default_depth` is how many hops of neighbours are shown by default.
    |
    */

    'focus' => [
        'default_depth' => (int) env('TRUSS_FOCUS_DEPTH', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Large schema
    |--------------------------------------------------------------------------
    |
    | Table count above which the UI shows a "large schema — use focus/filter"
    | warning before rendering everything at once.
    |
    */

    'large_schema' => [
        'warn_above' => (int) env('TRUSS_LARGE_SCHEMA_WARN_ABOVE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema diff
    |--------------------------------------------------------------------------
    |
    | "What changed since the last migration". After each migration Truss keeps
    | the previous schema snapshot as a baseline and compares it against the
    | current one, surfacing added, removed, and changed tables, columns, indexes,
    | and foreign keys in the dashboard "Changes" panel and via `truss:diff`.
    |
    | This is the only feature that writes to the filesystem: the baseline is a
    | structure-only JSON file (never row data), stored on disk rather than in the
    | cache because it cannot be rebuilt from the live database once a migration
    | has run.
    |
    | `enabled`: master switch. When false, no baseline is captured, nothing is
    | written to disk, the "Changes" toggle is hidden, and `truss:diff` reports the
    | feature is off. Set it false if you do not want Truss touching your disk.
    |
    | `disk`: the filesystem disk the baseline is written to, `local` by default.
    | The path is always `truss/baselines/{connection}`. This deliberately does not
    | follow the application's default disk: the baseline is derived tooling state,
    | not application data, so it should not land in a production bucket, cost
    | money, or have several instances racing on one object. A failure to read or
    | write it is never fatal; the diff is simply unavailable until it recovers.
    |
    */

    'diff' => [
        'enabled' => (bool) env('TRUSS_DIFF_ENABLED', true),
        'disk' => env('TRUSS_DIFF_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Doctor
    |--------------------------------------------------------------------------
    |
    | `truss:doctor` reviews the schema for problems visible from structure
    | alone (a table with no primary key, an unindexed foreign key, and so on)
    | and can fail CI. Structure only: it never reads row data and makes no
    | network call.
    |
    |   preset:  recommended (high-confidence rules), strict (every rule), none.
    |   rules:   per-rule overrides keyed by code: false disables, true enables
    |            (even a heuristic one), ['severity' => 'error'] changes severity.
    |   ignore:  per-rule fnmatch patterns (table or table.column) to silence.
    |   fail_on:   the severity at or above which the command exits non-zero.
    |   exclude:   extra tables to skip, on top of truss.excluded_tables.
    |   dashboard: show the findings in the dashboard "Health" panel. When false,
    |              the schema endpoint sends no doctor payload and the panel and
    |              node badges never appear, leaving the CLI/CI doctor untouched.
    |   flag_tables: always mark tables that have findings on the diagram with a
    |              small severity count, even when the Health panel is closed. Set
    |              false to keep the diagram clean and surface findings only when
    |              the panel is open.
    |
    */

    'doctor' => [
        'preset' => env('TRUSS_DOCTOR_PRESET', 'recommended'),

        'rules' => [
            // 'TRUSS-INT-002' => true,
            // 'TRUSS-IDX-001' => ['severity' => 'error'],
        ],

        'ignore' => [
            // 'TRUSS-IDX-001' => ['audit_log.actor_id'],
        ],

        'fail_on' => env('TRUSS_DOCTOR_FAIL_ON', 'error'),

        'exclude' => [],

        'dashboard' => (bool) env('TRUSS_DOCTOR_DASHBOARD', true),

        'flag_tables' => (bool) env('TRUSS_DOCTOR_FLAG_TABLES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Annotations
    |--------------------------------------------------------------------------
    |
    | Business meaning that cannot be introspected: that status = 1 means paid,
    | that total_amount is integer cents, that legacy_orders is deprecated. It is
    | declared here (and/or read from native schema comments) and rendered into
    | the exports so a coding agent has grounding a type alone cannot give.
    |
    | This is still structure only: native comments are part of the CREATE TABLE
    | definition, not row content, the same boundary as column defaults.
    |
    |   source:  ordered precedence for resolving an annotation; first match
    |            wins. 'config' reads the maps below; 'database' reads native
    |            table/column comments (MySQL and Postgres; SQLite and SQL Server
    |            have none and are skipped). Drop 'database' to ignore DB comments.
    |   notes:   global notes rendered in a header block where the format has one.
    |   tables:  per-table annotations, keyed by table name.
    |   columns: per-column annotations, keyed by "table.column".
    |
    | --no-annotations (and the facade withoutAnnotations()) strip them all.
    |
    */

    'annotations' => [
        'source' => ['config', 'database'],

        'notes' => [
            // 'All timestamps are UTC.',
            // 'Monetary columns are integer cents unless stated.',
        ],

        'tables' => [
            // 'orders' => 'One row per order, not per line item.',
        ],

        'columns' => [
            // 'orders.status' => '0 draft, 1 paid, 2 refunded',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    |
    | `default_format`: the format truss:export and the facade use when none is
    | given. One of dbml, json, csv, markdown, mermaid, or llm.
    |
    */

    'export' => [
        'default_format' => env('TRUSS_EXPORT_FORMAT', 'dbml'),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    |
    | The optional read-only, structure-only MCP server exposes the live schema
    | to a coding agent (structure only, never row data). It requires the
    | optional first-party `laravel/mcp` package: install it with
    | `composer require laravel/mcp`. When that package is absent this switch has
    | no effect (the server is only registered when the package is present), so a
    | host that does not opt in is unaffected.
    |
    | `enabled`: master switch for registering the server once laravel/mcp is
    | installed. Defaults on, so installing the package is the only opt-in step.
    |
    */

    'mcp' => [
        'enabled' => (bool) env('TRUSS_MCP_ENABLED', true),
    ],

];
