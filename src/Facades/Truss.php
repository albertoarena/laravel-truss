<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Facades;

use AlbertoArena\Truss\TrussManager;
use Illuminate\Support\Facades\Facade;

/**
 * The public Truss facade.
 *
 * @method static \AlbertoArena\Truss\Export\ExportBuilder snapshot()
 *
 * @see TrussManager
 */
class Truss extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TrussManager::class;
    }
}
