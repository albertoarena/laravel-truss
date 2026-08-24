<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel Truss: Database Structure</title>

    {{-- Node-triad mark as an inline SVG favicon; themes with the browser chrome. --}}
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cstyle%3E*%7Bstroke:%2312356b;fill:none;stroke-width:1.7%7Dcircle%7Bfill:%2312356b;stroke:none%7D@media(prefers-color-scheme:dark)%7B*%7Bstroke:%235fd0e6%7Dcircle%7Bfill:%235fd0e6%7D%7D%3C/style%3E%3Cpath d='M16 5 L27 26 H5 Z'/%3E%3Cpath d='M16 5 V17'/%3E%3Cpath d='M5 26 L16 17'/%3E%3Cpath d='M27 26 L16 17'/%3E%3Ccircle cx='16' cy='5' r='2.4'/%3E%3Ccircle cx='5' cy='26' r='2.4'/%3E%3Ccircle cx='27' cy='26' r='2.4'/%3E%3Ccircle cx='16' cy='17' r='2.4'/%3E%3C/svg%3E">

    {{-- The diagram's label boxes are sized to the text Mermaid measures, with
         no slack, so a face arriving after that measurement repaints wider
         glyphs into boxes sized for the fallback and clips the last character
         (issue #59). The client waits for this face before measuring; the hint
         starts the fetch with the document instead of when the stylesheet is
         parsed, so that wait is usually over before it begins. crossorigin is
         required even same-origin, or the preload is not reused. --}}
    <link rel="preload" as="font" type="font/woff2"
          href="{{ route('truss.asset', 'ibm-plex-mono-400.woff2') }}" crossorigin>

    <link rel="stylesheet" href="{{ route('truss.asset', 'truss.css') }}">

    {{-- Optional custom-theme overrides, generated from truss.theme. Linked after
         the base sheet so its variables win, and only when a theme is configured
         (a default install makes no extra request). Same-origin, CSP-safe. --}}
    @if ($hasCustomTheme ?? false)
        <link rel="stylesheet" href="{{ route('truss.theme') }}">
    @endif

    {{-- Mermaid as a global (UMD). Self-hosted from the package by default (no
         CDN); config('truss.diagram.mermaid_url') opts into a CDN. --}}
    <script src="{{ config('truss.diagram.mermaid_url') ?: route('truss.asset', 'mermaid.min.js') }}"></script>
</head>
<body>
    <div
        id="truss-app"
        data-schema-endpoint="{{ route('truss.api.schema') }}"
        data-export-endpoint="{{ route('truss.export', ['format' => '__format__']) }}"
        data-connections='@json($connections)'
        data-type-labels="{{ config('truss.diagram.type_labels') }}"
        data-warn-above="{{ config('truss.large_schema.warn_above') }}"
        data-focus-depth="{{ config('truss.focus.default_depth') }}"
        data-min-zoom="{{ config('truss.diagram.min_zoom') }}"
        data-doctor-flag-tables="{{ config('truss.doctor.flag_tables', true) ? '1' : '0' }}"
    >
        <div class="truss-toolbar">
            <span class="truss-brand">
                <svg class="truss-mark" width="20" height="20" viewBox="0 0 32 32" fill="none" stroke="currentColor" aria-hidden="true">
                    <path d="M16 5 L27 26 H5 Z" stroke-width="1.7" stroke-linejoin="miter"/>
                    <path d="M16 5 V17" stroke-width="1.7"/><path d="M5 26 L16 17" stroke-width="1.7"/><path d="M27 26 L16 17" stroke-width="1.7"/>
                    <g fill="currentColor" stroke="none">
                        <circle cx="16" cy="5" r="2.4"/><circle cx="5" cy="26" r="2.4"/><circle cx="27" cy="26" r="2.4"/><circle cx="16" cy="17" r="2.4"/>
                    </g>
                </svg>
                truss
            </span>

            <label class="truss-field truss-field--search">
                <span class="truss-field-label">Filter</span>
                <input id="truss-search" type="search" placeholder="table name…" autocomplete="off">
            </label>

            <div class="truss-secondary" id="truss-more">
                {{-- Focus is a combobox rather than a select so it can be searched
                     by substring on a large schema (issue #39). Keyboard and
                     screen-reader behaviour is implemented in focus-combobox.js
                     to the ARIA combobox-with-listbox pattern. --}}
                <div class="truss-field truss-combo">
                    <label class="truss-field-label" for="truss-focus">Focus</label>
                    <input id="truss-focus" type="text" role="combobox" autocomplete="off"
                           aria-expanded="false" aria-controls="truss-focus-list"
                           aria-autocomplete="list" placeholder="none">
                    <ul class="truss-combo-list" id="truss-focus-list" role="listbox"
                        aria-label="Tables" hidden></ul>
                    <span class="truss-sr-only" id="truss-focus-status" role="status" aria-live="polite"></span>
                </div>
                <label class="truss-field">
                    <span class="truss-field-label">Depth</span>
                    <input id="truss-depth" type="number" min="0" step="1">
                </label>
                <label class="truss-field truss-field--check">
                    <input id="truss-labels" type="checkbox"> <span class="truss-field-label">Laravel types</span>
                </label>
            </div>

            <div class="truss-utils">
                <label class="truss-field truss-field--conn" hidden>
                    <span class="truss-field-label">Connections</span>
                    <select id="truss-connection"></select>
                </label>
                <button type="button" class="truss-util truss-util--more" id="truss-more-btn" title="More controls" aria-expanded="false">⋯</button>
                <button type="button" class="truss-util" id="truss-export-btn" title="Export the diagram (PNG or SVG)" aria-expanded="false">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 3 v11"/><path d="M7 9 l5 5 5-5"/><path d="M4 20 h16"/>
                    </svg>
                </button>
                <button type="button" class="truss-util" id="truss-diff-btn" title="What changed since the last migration" aria-expanded="false" hidden>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 8 h8 M9 4 v8"/><path d="M11 17 h8"/>
                    </svg>
                </button>
                <button type="button" class="truss-util" id="truss-health-btn" title="Structure health (truss:doctor findings)" aria-expanded="false" hidden>
                    <svg class="truss-health-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                        <path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/>
                    </svg>
                    <span class="truss-health-count" id="truss-health-count" aria-hidden="true" hidden></span>
                </button>
                <button type="button" class="truss-util" id="truss-legend-btn" title="Legend" aria-expanded="false">▤</button>
                <button type="button" class="truss-util" id="truss-theme-btn" title="Theme">◐</button>
            </div>
        </div>

        <div id="truss-banners"></div>

        <main id="truss-viewport">
            <div id="truss-canvas"></div>

            <div class="truss-zoom" id="truss-zoom">
                <button type="button" data-fit title="Fit the whole diagram in view">Fit</button>
                {{-- A title attribute is a tooltip, not a name: assistive technology
                     is not required to expose it, so the slider needs a real label
                     (WCAG 4.1.2). aria-label keeps the control visually unchanged. --}}
                <input id="truss-zoom-range" type="range" min="0.1" max="3" step="0.02" value="1"
                       aria-label="Zoom" title="Zoom (scroll or pinch over the diagram)">
                <span id="truss-zoom-pct">100%</span>
            </div>
        </main>

        <div class="truss-legend" id="truss-legend" hidden>
            <div class="truss-legend-head">Legend</div>
            <dl class="truss-legend-list">
                <dt>PK</dt><dd>Primary key</dd>
                <dt>FK</dt><dd>Foreign key</dd>
                <dt aria-hidden="true">
                    <svg width="42" height="12" viewBox="0 0 42 12" fill="none" stroke="currentColor" stroke-width="1.3">
                        <path d="M2 2 V10 M5 2 V10"/><path d="M5 6 H30"/>
                        <path d="M30 6 L38 2 M30 6 L38 10 M30 6 H38"/><circle cx="35" cy="6" r="2.4"/>
                    </svg>
                </dt><dd>One → many</dd>
            </dl>
        </div>

        <div class="truss-diff-panel" id="truss-diff-panel" hidden>
            <div class="truss-diff-head">Changes since last migration</div>
            <div class="truss-diff-body"></div>
        </div>

        <div class="truss-health-panel" id="truss-health-panel" hidden>
            <div class="truss-health-head">
                <span>Structure health</span>
                <button type="button" class="truss-health-max" id="truss-health-max-btn" title="Maximize" aria-label="Maximize" aria-pressed="false">⤢</button>
            </div>
            <div class="truss-health-body"></div>
        </div>

        <div class="truss-popover" id="truss-popover" hidden></div>

        <footer class="truss-footer">
            <span id="truss-stat-tables">&nbsp;</span>
            <span id="truss-stat-conn"></span>
            <span class="truss-flag" id="truss-stat-fallback" hidden>SQLite&nbsp;fallback</span>
            <span class="truss-footer-spacer"></span>
            <span id="truss-stat-updated"></span>
        </footer>
    </div>

    <script type="module" src="{{ route('truss.asset', 'truss.js') }}"></script>
</body>
</html>
