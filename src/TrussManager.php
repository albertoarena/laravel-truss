<?php

declare(strict_types=1);

namespace AlbertoArena\Truss;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Dashboard\DashboardPayload;
use AlbertoArena\Truss\Export\Contracts\CommentReader;
use AlbertoArena\Truss\Export\ExportBuilder;
use AlbertoArena\Truss\Export\SchemaExporter;
use InvalidArgumentException;

/**
 * The entry point behind the `Truss` facade, and the whole of Truss's public PHP
 * API. Two things live here:
 *
 *   - `snapshot()` hands out a fresh, immutable ExportBuilder for the fluent
 *     export API (`Truss::snapshot()->compact()->toDbml()`).
 *   - `payload()` returns what the dashboard runs on, in-process.
 *
 * Both are wired with the same collaborators the commands and routes use, so
 * calling either from inside the application gives what the HTTP surface gives.
 */
final class TrussManager
{
    public function __construct(
        private readonly SchemaCacheRepository $cache,
        private readonly SchemaExporter $exporter,
        private readonly CommentReader $commentReader,
        private readonly DashboardPayload $payload,
    ) {}

    public function snapshot(): ExportBuilder
    {
        return new ExportBuilder($this->cache, $this->exporter, $this->commentReader);
    }

    /**
     * The dashboard payload for a managed connection, defaulting to the app's
     * own: the exclusion-filtered snapshot with the structural diff and the
     * doctor findings embedded.
     *
     * This is byte-for-byte what `GET {prefix}/api/schema` serves, so anything
     * running inside the application (a Filament page, a Livewire component, a
     * command) can render the dashboard's data without making an HTTP request to
     * itself. Structure only, never row contents.
     *
     * Note that it does not consult the `viewTruss` gate: authorization belongs
     * to whatever is exposing the data. The route has the Authorize middleware
     * for that, and an in-app caller is responsible for its own check.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the connection is not one Truss manages
     */
    public function payload(?string $connection = null): array
    {
        return $this->payload->for($connection);
    }
}
