<?php

declare(strict_types=1);

// The differ is pure logic over two serialized snapshots: it must never reach
// into the web, view, cache, routing, or filesystem stack. (BaselineStore, which
// deliberately persists to a disk, is a separate class and is not covered here.)

arch('the schema differ has no framework, I/O or render dependencies')
    ->expect('AlbertoArena\Truss\Diff\SchemaDiffer')
    ->not->toUse([
        'Illuminate\Http',
        'Illuminate\Routing',
        'Illuminate\View',
        'Illuminate\Cache',
        'Illuminate\Filesystem',
        'Illuminate\Support\Facades\Cache',
        'Illuminate\Support\Facades\Storage',
        'Illuminate\Support\Facades\View',
        'Illuminate\Support\Facades\Route',
    ]);
