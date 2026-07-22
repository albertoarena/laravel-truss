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
    | Global on/off switch. Defaults to enabled only in the local environment,
    | matching the Telescope/Horizon convention. Authorization is enforced
    | separately by the fixed `viewTruss` gate.
    |
    */

    'enabled' => env('TRUSS_ENABLED', env('APP_ENV', 'production') === 'local'),

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

];
