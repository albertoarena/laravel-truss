<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use AlbertoArena\Truss\Export\Contracts\Generator;
use AlbertoArena\Truss\Export\Html\AssetInliner;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * The whole diagram as one self-contained HTML document: no server, no network,
 * no database, and no Truss install needed to open it. Structure only, like
 * every other generator.
 *
 * Unlike the six text formats this one is a document rather than a rendering of
 * the tables, so it carries the package's own stylesheet, fonts, Mermaid and
 * frontend modules inline. Those arrive as constructor dependencies and
 * `generate()` stays deterministic over its inputs.
 *
 * It renders the dashboard's own Blade view in export mode rather than holding a
 * second copy of the markup. A second copy is how the demo shells in the docs
 * site drifted, and they needed a structural guard to catch it; one more would
 * be a fourth.
 *
 * Two keys are deliberately kept out of the embedded payload. `generated_at`
 * changes on every snapshot rebuild and `diff` is a comparison against a
 * baseline the reader of a detached file cannot see, so either would make a
 * committed export drift under `--check` on a day when no column moved.
 */
class HtmlGenerator implements Generator
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly AssetInliner $assets = new AssetInliner,
        /** Mermaid from a URL instead of inline: smaller file, needs a network. */
        private readonly ?string $mermaidUrl = null,
        /** Doctor findings, supplied by the caller that knows the connection. */
        private readonly ?array $doctor = null,
    ) {}

    public function generate(array $tables, array $notes = []): string
    {
        // truss-package, not truss: a published copy of the dashboard view
        // would render this export with route() URLs and no inlined assets, so
        // the file would reach for a server the reader cannot see. See
        // TrussServiceProvider::registerPackageViewNamespace().
        return $this->views->make('truss-package::index', [
            'export' => true,
            'connections' => [],
            'hasCustomTheme' => false,
            'inlineCss' => $this->assets->css(),
            'inlineMermaid' => $this->mermaidUrl === null ? $this->assets->mermaid() : null,
            'mermaidUrl' => $this->mermaidUrl,
            'inlineModules' => $this->assets->modules(),
            'payloadJson' => $this->payload($tables),
        ])->render();
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     */
    private function payload(array $tables): string
    {
        $payload = [
            'tables' => $tables,
            // Always present, and zero here: the exporter has already applied
            // both the config exclusions and the CLI filters, so nothing was
            // held back from this document that the footer should confess to.
            'excluded' => ['count' => 0],
        ];

        if ($this->doctor !== null) {
            $payload['doctor'] = $this->doctor;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
