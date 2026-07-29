<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor;

use AlbertoArena\Truss\Doctor\Contracts\Rule;

/**
 * Runs a resolved set of rules against one snapshot and returns a sorted
 * FindingCollection. This is where the config-driven concerns live, so rules
 * stay pure: per-code severity overrides and ignore patterns. Rule selection
 * (presets, --only, --skip, enable/disable) happens before this and is handed in
 * as the already-resolved rule list.
 */
final class DoctorRunner
{
    /**
     * @param  list<Rule>  $rules
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, Severity>  $severityOverrides  rule code => severity
     * @param  array<string, list<string>>  $ignore  rule code => fnmatch patterns (table or table.column)
     */
    public function run(
        array $rules,
        array $snapshot,
        string $connection,
        array $severityOverrides = [],
        array $ignore = [],
    ): FindingCollection {
        $findings = [];

        foreach ($rules as $rule) {
            foreach ($rule->check($snapshot, $connection) as $finding) {
                if (isset($severityOverrides[$finding->code])) {
                    $finding = $finding->withSeverity($severityOverrides[$finding->code]);
                }

                if ($this->isIgnored($finding, $ignore)) {
                    continue;
                }

                $findings[] = $finding;
            }
        }

        return (new FindingCollection(...$findings))->sorted();
    }

    /**
     * True when the finding matches an ignore pattern registered under its own
     * rule code. A pattern is fnmatch'd against the table and, when the finding
     * has a column, against "table.column".
     *
     * @param  array<string, list<string>>  $ignore
     */
    private function isIgnored(Finding $finding, array $ignore): bool
    {
        foreach ($ignore[$finding->code] ?? [] as $pattern) {
            if (fnmatch($pattern, $finding->table)) {
                return true;
            }

            if ($finding->column !== null && fnmatch($pattern, "{$finding->table}.{$finding->column}")) {
                return true;
            }
        }

        return false;
    }
}
