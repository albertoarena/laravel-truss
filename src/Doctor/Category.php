<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor;

/**
 * The rule groups. The string value is the token users pass to `--only` and
 * `--skip`, so it is part of the CLI contract.
 */
enum Category: string
{
    case Integrity = 'integrity';
    case Index = 'index';
    case Type = 'type';
    case Naming = 'naming';
    case Laravel = 'laravel';
}
