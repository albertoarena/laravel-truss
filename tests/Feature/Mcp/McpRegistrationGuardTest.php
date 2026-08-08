<?php

declare(strict_types=1);

use AlbertoArena\Truss\TrussServiceProvider;
use Laravel\Mcp\Facades\Mcp;

/*
 * Phase 0 scaffold for the optional MCP server (workstream G). laravel/mcp is an
 * optional `suggest` dependency and is not installed in the test environment, so
 * these lock in the guarantee that a host without it is byte-identical to today:
 * the guard short-circuits on the missing class even with the feature enabled.
 * The class-present branch is covered in Phase 3, once the server class ships.
 */

it('defaults truss.mcp.enabled to true', function () {
    expect(config('truss.mcp.enabled'))->toBeTrue();
});

it('does not register the MCP server when laravel/mcp is absent', function () {
    expect(class_exists(Mcp::class))->toBeFalse()
        ->and(TrussServiceProvider::shouldRegisterMcpServer())->toBeFalse();
});

it('does not register the MCP server when the feature is disabled', function () {
    config()->set('truss.mcp.enabled', false);

    expect(TrussServiceProvider::shouldRegisterMcpServer())->toBeFalse();
});
