<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Formatters;

use AlbertoArena\Truss\Doctor\Contracts\Formatter;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\FindingCollection;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A plain-text report for the terminal: a one-line summary, then the findings as
 * a bordered table grouped by table (a full-width header row per table, then one
 * row per finding). Long messages wrap inside their column so the table stays a
 * comfortable width. Deliberately free of ANSI colour so the command can style it
 * and so the output is stable to snapshot in tests.
 */
final class ConsoleFormatter implements Formatter
{
    /**
     * Width the message column wraps at. Keeps the table narrow enough for a
     * typical terminal while leaving the message readable.
     */
    private const MESSAGE_WIDTH = 60;

    public function format(FindingCollection $findings): string
    {
        if ($findings->isEmpty()) {
            return "Truss doctor: no findings.\n";
        }

        $buffer = new BufferedOutput;

        $table = (new Table($buffer))
            ->setColumnMaxWidth(3, self::MESSAGE_WIDTH);

        $first = true;

        foreach ($this->groupByTable($findings) as $tableName => $tableFindings) {
            if (! $first) {
                $table->addRow(new TableSeparator);
            }

            $table->addRow([new TableCell($tableName, [
                'colspan' => 4,
                'style' => new TableCellStyle(['cellFormat' => '<info>%s</info>']),
            ])]);
            $table->addRow(new TableSeparator);

            foreach ($tableFindings as $finding) {
                $table->addRow([
                    $finding->severity->value,
                    $finding->code,
                    $finding->column ?? '(table)',
                    $finding->message,
                ]);
            }

            $first = false;
        }

        $table->render();

        return $this->summaryLine($findings)."\n\n".$buffer->fetch();
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
