<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Truss — Database Structure</title>

    <link rel="stylesheet" href="{{ route('truss.asset', 'truss.css') }}">

    {{-- Mermaid as a global (UMD). Self-hosted from the package by default (no
         CDN, CSP-friendly); config('truss.diagram.mermaid_url') opts into a CDN
         or a custom copy. The app module is deferred, so window.mermaid is ready
         before it runs. --}}
    <script src="{{ config('truss.diagram.mermaid_url') ?: route('truss.asset', 'mermaid.min.js') }}"></script>
</head>
<body>
    <div
        id="truss-app"
        data-schema-endpoint="{{ route('truss.api.schema') }}"
        data-connections='@json($connections)'
        data-type-labels="{{ config('truss.diagram.type_labels') }}"
        data-theme="{{ config('truss.diagram.theme') }}"
        data-warn-above="{{ config('truss.large_schema.warn_above') }}"
        data-focus-depth="{{ config('truss.focus.default_depth') }}"
    >
        <div class="truss-toolbar">
            <span class="truss-brand">Truss</span>

            <label class="truss-field" hidden>
                Connection
                <select id="truss-connection"></select>
            </label>

            <label class="truss-field">
                Filter
                <input id="truss-search" type="search" placeholder="table name…" autocomplete="off">
            </label>

            <label class="truss-field">
                Focus
                <select id="truss-focus"></select>
            </label>

            <label class="truss-field">
                depth
                <input id="truss-depth" type="number" min="0" step="1">
            </label>

            <label class="truss-field">
                <input id="truss-labels" type="checkbox"> Laravel types
            </label>

            <span class="truss-zoom">
                <button type="button" data-zoom="out" title="Zoom out">&minus;</button>
                <button type="button" data-zoom="reset" title="Reset zoom">reset</button>
                <button type="button" data-zoom="in" title="Zoom in">&plus;</button>
            </span>
        </div>

        <div id="truss-banners"></div>

        <main id="truss-viewport">
            <div id="truss-canvas"></div>
        </main>
    </div>

    <script type="module" src="{{ route('truss.asset', 'truss.js') }}"></script>
</body>
</html>
