<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Truss — Database Structure</title>

    {{-- Node-triad mark as an inline SVG favicon; themes with the browser chrome. --}}
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cstyle%3E*%7Bstroke:%2312356b;fill:none;stroke-width:1.7%7Dcircle%7Bfill:%2312356b;stroke:none%7D@media(prefers-color-scheme:dark)%7B*%7Bstroke:%235fd0e6%7Dcircle%7Bfill:%235fd0e6%7D%7D%3C/style%3E%3Cpath d='M16 5 L27 26 H5 Z'/%3E%3Cpath d='M16 5 V17'/%3E%3Cpath d='M5 26 L16 17'/%3E%3Cpath d='M27 26 L16 17'/%3E%3Ccircle cx='16' cy='5' r='2.4'/%3E%3Ccircle cx='5' cy='26' r='2.4'/%3E%3Ccircle cx='27' cy='26' r='2.4'/%3E%3Ccircle cx='16' cy='17' r='2.4'/%3E%3C/svg%3E">

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
        data-min-zoom="{{ config('truss.diagram.min_zoom') }}"
    >
        <div class="truss-toolbar">
            <span class="truss-brand">
                <svg class="truss-mark" width="20" height="20" viewBox="0 0 32 32" fill="none" stroke="currentColor" aria-hidden="true">
                    <path d="M16 5 L27 26 H5 Z" stroke-width="1.7" stroke-linejoin="miter"/>
                    <path d="M16 5 V17" stroke-width="1.7"/>
                    <path d="M5 26 L16 17" stroke-width="1.7"/>
                    <path d="M27 26 L16 17" stroke-width="1.7"/>
                    <g fill="currentColor" stroke="none">
                        <circle cx="16" cy="5" r="2.4"/><circle cx="5" cy="26" r="2.4"/>
                        <circle cx="27" cy="26" r="2.4"/><circle cx="16" cy="17" r="2.4"/>
                    </g>
                </svg>
                Truss
            </span>

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
                <button type="button" data-fit title="Fit the diagram to the screen">Fit</button>
                <input id="truss-zoom-range" type="range" min="0.1" max="3" step="0.02" value="1"
                       title="Zoom (or scroll over the diagram, drag to pan)">
                <span id="truss-zoom-pct">100%</span>
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
