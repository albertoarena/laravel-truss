<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli\Commands;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Support\ExcludedTables;
use Illuminate\Container\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The database structure as a terminal table: the binary's counterpart to
 * `php artisan truss:show`, reading the same snapshot through the same service.
 *
 * Structure only, which here is the table name, how many columns it has and how
 * many foreign keys leave it. Never a row.
 *
 * Two differences from the artisan twin, both deliberate. **There is no cache
 * warning**, because the CLI's cache is an array store that dies with the
 * process: the snapshot is always built live, so a notice saying so would fire
 * on every single run and mean nothing. **And there is no pointer to the
 * dashboard**, since `truss:open` needs a route and there is none here.
 */
#[AsCommand(name: 'show', description: 'Print the database structure as a table')]
final class ShowCommand extends ConnectionCommand
{
    protected function handle(InputInterface $input, OutputInterface $output, Container $container): int
    {
        $snapshot = $container->make(SchemaCacheRepository::class)->get();
        $connection = (string) $snapshot['connection'];

        // The snapshot is deliberately unfiltered, so filtering is the caller's
        // job. Through the same class the artisan command uses, so the two
        // cannot answer differently about one database.
        $known = $snapshot['tables'] ?? [];
        $tables = ExcludedTables::filter($known, $connection);

        if ($tables === []) {
            $output->writeln($known === []
                ? '<comment>No tables found.</comment>'
                : '<comment>Every table in this database is excluded by config.</comment>');

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Table', 'Columns', 'Foreign keys']);
        $table->setRows(array_map(static fn (array $row): array => [
            $row['name'],
            (string) count($row['columns'] ?? []),
            (string) count($row['foreign_keys'] ?? []),
        ], $tables));
        $table->render();

        $output->writeln($this->scope(count($tables), count($known)).'.');

        return self::SUCCESS;
    }

    /**
     * How much of the database is on screen: "2 tables" when that is all of
     * them, "2 of 10 tables" when config hid the rest.
     *
     * Phrased as scope rather than as concealment, exactly as the artisan
     * command and the dashboard footer phrase it. It answers the question a
     * reader has when the list looks short, and it never names what was left
     * out.
     */
    private function scope(int $shown, int $known): string
    {
        if ($shown === $known) {
            return '<info>'.$shown.'</info> '.($shown === 1 ? 'table' : 'tables');
        }

        return '<info>'.$shown.'</info> of <info>'.$known.'</info> '.($known === 1 ? 'table' : 'tables');
    }
}
