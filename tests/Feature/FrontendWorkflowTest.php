<?php

declare(strict_types=1);

/*
 * The frontend workflow, guarded as text, as the release workflow is.
 *
 * The Playwright browser install downloads two browsers and system packages.
 * A slow mirror once held it for 14 minutes, and with no timeout a stalled
 * download waits for the six-hour job limit of GitHub Actions.
 */

function frontendWorkflow(): string
{
    $path = dirname(__DIR__, 2).'/.github/workflows/frontend.yml';

    expect($path)->toBeFile();

    return (string) file_get_contents($path);
}

/** The YAML block of one step, from its name to the next step. */
function frontendStep(string $name): string
{
    $workflow = frontendWorkflow();
    $start = strpos($workflow, "- name: {$name}\n");

    expect($start)->not->toBeFalse("No step named [{$name}].");

    $next = strpos($workflow, "\n      - name:", $start + 1);

    return substr($workflow, $start, $next === false ? null : $next - $start);
}

it('limits the whole job, so no step can hold it for six hours', function (): void {
    expect(frontendWorkflow())->toMatch('/\n    timeout-minutes: (\d|1\d|20)\n/');
});

it('limits the browser install, so a stalled download fails fast', function (): void {
    expect(frontendStep('Install Playwright browsers'))->toMatch('/timeout-minutes: (\d|10)\n/');
});

it('caches the browsers for the Playwright version that npm installed', function (): void {
    expect(frontendStep('Cache Playwright browsers'))
        ->toContain('path: ~/.cache/ms-playwright')
        ->toContain('key: playwright-${{ runner.os }}-${{ steps.playwright.outputs.version }}');
});

it('installs only the system packages when the browsers come from the cache', function (): void {
    expect(frontendStep('Install Playwright browsers'))
        ->toContain("if: steps.playwright-cache.outputs.cache-hit != 'true'")
        ->and(frontendStep('Install Playwright system packages'))
        ->toContain("if: steps.playwright-cache.outputs.cache-hit == 'true'")
        ->toContain('npx playwright install-deps chromium firefox');
});

it('pins every action to a full commit, as the rest of this repository does', function (): void {
    preg_match_all('/uses:\s*(\S+)/', frontendWorkflow(), $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $ref) {
        expect($ref)->toMatch('/@[0-9a-f]{40}$/', "[{$ref}] is not pinned to a full commit SHA.");
    }
});
