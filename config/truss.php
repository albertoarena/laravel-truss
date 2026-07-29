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
        'theme' => env('TRUSS_DIAGRAM_THEME', 'default'),

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
    | `disk`: the filesystem disk the baseline is written to. Null uses the
    | application's default disk. The path is always `truss/baselines/{connection}`.
    |
    */

    'diff' => [
        'enabled' => (bool) env('TRUSS_DIFF_ENABLED', true),
        'disk' => env('TRUSS_DIFF_DISK'),
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
    |   fail_on: the severity at or above which the command exits non-zero.
    |   exclude: extra tables to skip, on top of truss.excluded_tables.
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
    ],

];
