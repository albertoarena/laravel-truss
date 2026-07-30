<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Formatters;

use AlbertoArena\Truss\Doctor\Contracts\Formatter;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\FindingCollection;

/**
 * Structured JSON for tooling and CI: every finding with its fingerprint, plus a
 * severity summary. Slashes are left unescaped so paths and messages read cleanly.
 */
final class JsonFormatter implements Formatter
{
    public function format(FindingCollection $findings): string
    {
        $data = [
            'findings' => array_map($this->finding(...), $findings->all()),
            'summary' => $this->summary($findings),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(Finding $finding): array
    {
        return [
            'code' => $finding->code,
            'severity' => $finding->severity->value,
            'connection' => $finding->connection,
            'table' => $finding->table,
            'column' => $finding->column,
            'message' => $finding->message,
            'hint' => $finding->hint,
            'suggestion' => $finding->suggestion,
            'fingerprint' => $finding->fingerprint(),
        ];
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
