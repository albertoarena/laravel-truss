<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Assembles and runs the doctor for one connection: injects the live driver into
 * the snapshot, drops excluded tables, resolves the active rule set from the
 * preset and config, and runs the rules with the configured severity overrides
 * and ignore patterns. Both the `truss:doctor` command and the dashboard schema
 * endpoint go through here, so the CLI and the browser see identical findings.
 *
 * Structure only: it works on the schema snapshot and never touches row data.
 */
final class DoctorReport
{
    public function __construct(private readonly DoctorRunner $runner = new DoctorRunner) {}

    /**
     * Run the doctor for a connection over an already-loaded snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $only  category tokens to keep
     * @param  list<string>  $skip  category tokens to drop
     */
    public function for(
        string $connection,
        array $snapshot,
        ?string $preset = null,
        array $only = [],
        array $skip = [],
        ?string $table = null,
    ): FindingCollection {
        $preset ??= (string) config('truss.doctor.preset', 'recommended');

        $snapshot['driver'] = $this->driverFor($connection);
        $snapshot['tables'] = $this->selectTables($snapshot['tables'] ?? [], $connection, $table);

        $rules = RuleRegistry::default()->resolve(
            $preset,
            $only,
            $skip,
            $this->enabledOverrides(),
        );

        return $this->runner->run(
            $rules,
            $snapshot,
            $connection,
            $this->severityOverrides(),
            (array) config('truss.doctor.ignore', []),
        );
    }

    /**
     * A JSON-ready payload: the severity summary plus every finding, each carrying
     * its rule's confidence and category so a client can mark heuristics and group
     * by category without a second lookup.
     *
     * @return array{summary: array{total: int, error: int, warning: int, info: int}, findings: list<array<string, mixed>>}
     */
    public function toArray(FindingCollection $findings): array
    {
        $meta = $this->ruleMetadata();

        return [
            'summary' => $this->summary($findings),
            'findings' => array_map(fn (Finding $finding): array => [
                'code' => $finding->code,
                'severity' => $finding->severity->value,
                'connection' => $finding->connection,
                'table' => $finding->table,
                'column' => $finding->column,
                'message' => $finding->message,
                'hint' => $finding->hint,
                'suggestion' => $finding->suggestion,
                'confidence' => $meta[$finding->code]['confidence'] ?? Confidence::High->value,
                'category' => $meta[$finding->code]['category'] ?? null,
                'fingerprint' => $finding->fingerprint(),
            ], $findings->all()),
        ];
    }

    private function driverFor(string $connection): string
    {
        try {
            return DB::connection($connection)->getDriverName();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Drop excluded tables, and narrow to a single table when given.
     *
     * @param  list<array<string, mixed>>  $tables
     * @return list<array<string, mixed>>
     */
    private function selectTables(array $tables, string $connection, ?string $only): array
    {
        $excluded = array_merge(
            (array) config('truss.excluded_tables', []),
            (array) config("truss.connections.{$connection}.excluded_tables", []),
            (array) config('truss.doctor.exclude', []),
        );

        return array_values(array_filter($tables, fn (array $table): bool => ! in_array($table['name'], $excluded, true)
            && ($only === null || $table['name'] === $only)));
    }

    /**
     * config('truss.doctor.rules') as a code => on/off map (false disables;
     * true or a severity override enables).
     *
     * @return array<string, bool>
     */
    private function enabledOverrides(): array
    {
        $overrides = [];

        foreach ((array) config('truss.doctor.rules', []) as $code => $value) {
            $overrides[$code] = $value !== false;
        }

        return $overrides;
    }

    /**
     * @return array<string, Severity>
     */
    private function severityOverrides(): array
    {
        $overrides = [];

        foreach ((array) config('truss.doctor.rules', []) as $code => $value) {
            if (is_array($value) && isset($value['severity'])) {
                $overrides[$code] = Severity::from((string) $value['severity']);
            }
        }

        return $overrides;
    }

    /**
     * Rule code => confidence and category, read once from the default registry.
     *
     * @return array<string, array{confidence: string, category: string}>
     */
    private function ruleMetadata(): array
    {
        $meta = [];

        foreach (RuleRegistry::default()->all() as $rule) {
            $meta[$rule->code()] = [
                'confidence' => $rule->confidence()->value,
                'category' => $rule->category()->value,
            ];
        }

        return $meta;
    }

    /**
     * @return array{total: int, error: int, warning: int, info: int}
     */
    private function summary(FindingCollection $findings): array
    {
        $summary = ['total' => count($findings), 'error' => 0, 'warning' => 0, 'info' => 0];

        foreach ($findings as $finding) {
            $summary[$finding->severity->value]++;
        }

        return $summary;
    }
}
