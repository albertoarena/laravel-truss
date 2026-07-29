<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Formatters;

use AlbertoArena\Truss\Doctor\Contracts\Formatter;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\FindingCollection;

/**
 * A plain-text report for the terminal: a one-line summary, then the findings
 * grouped by table. Deliberately free of ANSI colour so the command can style it
 * and so the output is stable to snapshot in tests.
 */
final class ConsoleFormatter implements Formatter
{
    public function format(FindingCollection $findings): string
    {
        if ($findings->isEmpty()) {
            return "Truss doctor: no findings.\n";
        }

        $lines = [$this->summaryLine($findings), ''];

        foreach ($this->groupByTable($findings) as $table => $tableFindings) {
            $lines[] = $table;

            foreach ($tableFindings as $finding) {
                $location = $finding->column !== null ? "{$finding->table}.{$finding->column}" : $finding->table;
                $lines[] = sprintf('  %-7s %s  %s  %s', $finding->severity->value, $finding->code, $location, $finding->message);
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function summaryLine(FindingCollection $findings): string
    {
        $counts = $this->counts($findings);
        $total = count($findings);
        $noun = $total === 1 ? 'finding' : 'findings';

        return "Truss doctor: {$total} {$noun} ({$counts['error']} error, {$counts['warning']} warning, {$counts['info']} info)";
    }

    /**
     * @return array{error: int, warning: int, info: int}
     */
    private function counts(FindingCollection $findings): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];

        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * @return array<string, list<Finding>>
     */
    private function groupByTable(FindingCollection $findings): array
    {
        $grouped = [];

        foreach ($findings as $finding) {
            $grouped[$finding->table][] = $finding;
        }

        return $grouped;
    }
}
