<?php

declare(strict_types=1);

// Enforces the rule in src/Introspection/CLAUDE.md: this layer knows only about
// the database. It must never reach into the web, view, cache, or render stack.
// Database access (connections, the schema builder) is expected and allowed.

arch('the introspection layer has no HTTP, view, cache or routing dependencies')
    ->expect('AlbertoArena\Truss\Introspection')
    ->not->toUse([
        'Illuminate\Http',
        'Illuminate\Routing',
        'Illuminate\View',
        'Illuminate\Cache',
        'Illuminate\Support\Facades\Cache',
        'Illuminate\Support\Facades\View',
        'Illuminate\Support\Facades\Route',
        'Illuminate\Support\Facades\Blade',
    ]);

arch('the introspection value objects are immutable')
    ->expect('AlbertoArena\Truss\Introspection\Data')
    ->toBeReadonly();
