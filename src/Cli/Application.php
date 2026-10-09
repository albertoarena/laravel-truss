<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli;

use AlbertoArena\Truss\Cli\Commands\DiffCommand;
use AlbertoArena\Truss\Cli\Commands\DoctorCommand;
use AlbertoArena\Truss\Cli\Commands\ExportCommand;
use AlbertoArena\Truss\Cli\Commands\ShowCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * The console application the binary runs.
 *
 * It ships fewer commands than the artisan integration, and the gap is a
 * decision rather than an unfinished list. `truss:open` opens the dashboard in
 * a browser, which needs a route and an application URL: framework-free there
 * is no route, no server and nothing to point a browser at. `truss:rebuild`
 * writes the snapshot into a cache store so later requests are fast, and the
 * CLI's store is an array that dies with the process, so the command would
 * report success for having done nothing. Both absences are asserted in the
 * test suite, so that adding either one is a deliberate act rather than a
 * reflex to make the two surfaces look alike.
 */
final class Application extends SymfonyApplication
{
    public const NAME = 'Truss';

    public static function create(): self
    {
        $application = new self(self::NAME, Version::current());

        $application->addCommands([
            new ShowCommand,
            new ExportCommand,
            new DoctorCommand,
            new DiffCommand,
        ]);

        return $application;
    }
}
