<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Truss — Database Structure</title>

    {{-- Mermaid as a global (UMD) from a CDN — no build step. The app module is
         deferred, so window.mermaid is ready before it runs. --}}
    <script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>

    <style>
        :root { color-scheme: light dark; --bd: #d8dde3; --bg: #f6f7f9; --fg: #1b1f24; --muted: #5b6570; --accent: #2f6feb; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.5 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color: var(--fg); background: var(--bg); }
        .truss-toolbar { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; padding: 10px 16px; border-bottom: 1px solid var(--bd); background: #fff; position: sticky; top: 0; z-index: 5; }
        .truss-brand { font-weight: 700; letter-spacing: .3px; margin-right: 4px; }
        .truss-field { display: flex; align-items: center; gap: 6px; color: var(--muted); }
        .truss-field[hidden] { display: none; }
        .truss-toolbar input, .truss-toolbar select { font: inherit; padding: 4px 8px; border: 1px solid var(--bd); border-radius: 6px; background: #fff; color: var(--fg); }
        .truss-toolbar input[type=number] { width: 56px; }
        .truss-zoom { margin-left: auto; display: inline-flex; gap: 4px; }
        .truss-zoom button { font: inherit; width: 32px; height: 30px; border: 1px solid var(--bd); border-radius: 6px; background: #fff; cursor: pointer; }
        .truss-zoom button[data-zoom=reset] { width: auto; padding: 0 10px; }
        #truss-banners { display: flex; flex-direction: column; gap: 1px; }
        .truss-banner { padding: 8px 16px; font-size: 13px; }
        .truss-banner--info { background: #eef3ff; color: #294b9c; }
        .truss-banner--warn { background: #fff6e6; color: #8a5a00; }
        .truss-banner--error { background: #fdecec; color: #a11; }
        #truss-viewport { overflow: auto; height: calc(100vh - 52px); padding: 20px; }
        #truss-canvas { display: inline-block; transform-origin: top left; }
        header.truss-hidden { display: none; }
    </style>
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

    <script type="module" src="{{ asset('vendor/truss/truss.js') }}"></script>
</body>
</html>
