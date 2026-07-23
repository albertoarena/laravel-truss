<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('prints the dashboard URL and opens it in the browser', function () {
    Process::fake();
    $url = route('truss.index');

    $this->artisan('truss:open')
        ->assertSuccessful()
        ->expectsOutputToContain($url);

    Process::assertRan(function ($process) use ($url) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, $url);
    });
});
