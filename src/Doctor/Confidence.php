<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor;

/**
 * How sure a rule is that a finding is a real problem, kept separate from how
 * bad it is (severity). The `recommended` preset runs High-confidence rules
 * only, so heuristic rules stay quiet by default; `strict` adds them. A rule can
 * be heuristic yet still emit a warning when it does fire.
 */
enum Confidence: string
{
    case High = 'high';
    case Heuristic = 'heuristic';
}
