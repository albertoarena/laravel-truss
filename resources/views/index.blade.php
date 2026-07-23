<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Truss — Database Structure</title>
</head>
<body>
    {{--
        Shell only. The frontend (Phase 6) fetches the schema from
        data-schema-endpoint and renders it with Mermaid client-side; no schema
        data is inlined into this page.
    --}}
    <div
        id="truss-app"
        data-schema-endpoint="{{ route('truss.api.schema') }}"
        data-default-connection="{{ config('database.default') }}"
        data-type-labels="{{ config('truss.diagram.type_labels') }}"
        data-warn-above="{{ config('truss.large_schema.warn_above') }}"
        data-focus-depth="{{ config('truss.focus.default_depth') }}"
    >
        <header>
            <h1>Truss</h1>
            <p>Live database structure. Structure only — no row data is ever shown.</p>
        </header>

        <main id="truss-diagram"></main>
    </div>
</body>
</html>
