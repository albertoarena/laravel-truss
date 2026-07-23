<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Open the Truss dashboard in the default browser. The URL honours the
 * configured route prefix and the application URL. On a headless host the URL is
 * printed regardless, so it can be opened by hand or port-forwarded, and a note
 * is shown when Truss is not enabled in the current environment.
 */
class OpenCommand extends Command
{
    protected $signature = 'truss:open';

    protected $description = 'Open the Truss dashboard in your browser';

    public function handle(): int
    {
        $url = route('truss.index');

        $this->line('Truss: <info>'.$url.'</info>');

        if (! config('truss.enabled')) {
            $this->warn('Truss is not enabled in this environment; set TRUSS_ENABLED=true or run it locally.');
        }

        Process::run($this->openCommand($url));

        return self::SUCCESS;
    }

    /**
     * The OS-specific command that opens a URL in the default browser.
     *
     * @return list<string>
     */
    private function openCommand(string $url): array
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $url],
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => ['xdg-open', $url],
        };
    }
}
