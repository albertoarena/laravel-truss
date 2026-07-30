<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Category;

it('exposes the CLI tokens used by --only and --skip', function () {
    expect(array_map(fn (Category $c): string => $c->value, Category::cases()))
        ->toBe(['integrity', 'index', 'type', 'naming', 'laravel']);
});
