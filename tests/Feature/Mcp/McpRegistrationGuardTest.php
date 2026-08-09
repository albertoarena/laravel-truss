<?php

declare(strict_types=1);

use AlbertoArena\Truss\TrussServiceProvider;
use Laravel\Mcp\Server\Registrar;

/*
 * The MCP server registration guard. laravel/mcp is installed here (require-dev),
 * so this covers the class-present branch Phase 0 deferred: with the feature
 * enabled the local server is registered, and disabling it (or, in a host, not
 * installing laravel/mcp) leaves the app untouched.
 */

it('defaults truss.mcp.enabled to true', function () {
    expect(config('truss.mcp.enabled'))->toBeTrue();
});

it('registers the local MCP server when laravel/mcp is installed and enabled', function () {
    expect(TrussServiceProvider::shouldRegisterMcpServer())->toBeTrue()
        ->and(app(Registrar::class)->getLocalServer('truss'))->not->toBeNull();
});

it('does not register the MCP server when the feature is disabled', function () {
    config()->set('truss.mcp.enabled', false);

    expect(TrussServiceProvider::shouldRegisterMcpServer())->toBeFalse();
});
