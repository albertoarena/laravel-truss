<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor;

use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Rules\Index\DuplicateIndex;
use AlbertoArena\Truss\Doctor\Rules\Index\ForeignKeyWithoutIndex;
use AlbertoArena\Truss\Doctor\Rules\Index\IndexDuplicatingPrimaryKey;
use AlbertoArena\Truss\Doctor\Rules\Index\MissingUniqueConstraint;
use AlbertoArena\Truss\Doctor\Rules\Index\RedundantPrefixIndex;
use AlbertoArena\Truss\Doctor\Rules\Index\UnindexedSoftDelete;
use AlbertoArena\Truss\Doctor\Rules\Integrity\ForeignKeyTypeMismatch;
use AlbertoArena\Truss\Doctor\Rules\Integrity\LikelyMissingForeignKey;
use AlbertoArena\Truss\Doctor\Rules\Integrity\MissingPrimaryKey;
use AlbertoArena\Truss\Doctor\Rules\Integrity\PivotWithoutUniqueKey;
use AlbertoArena\Truss\Doctor\Rules\Integrity\PolymorphicWithoutIndex;
use AlbertoArena\Truss\Doctor\Rules\Type\BooleanAsString;
use AlbertoArena\Truss\Doctor\Rules\Type\MoneyAsFloat;

/**
 * Holds the available rules and resolves the active set for a run. Selection is
 * kept out of the runner: given a preset, category filters, and per-rule
 * enable/disable, this returns the rules to run. Severity overrides and ignore
 * patterns are the runner's job, not the registry's.
 */
final class RuleRegistry
{
    /** @var list<Rule> */
    private array $rules;

    public function __construct(Rule ...$rules)
    {
        $this->rules = array_values($rules);
    }

    /**
     * The Phase 1 rule set, in code order.
     */
    public static function default(): self
    {
        return new self(
            new MissingPrimaryKey,
            new LikelyMissingForeignKey,
            new ForeignKeyTypeMismatch,
            new PivotWithoutUniqueKey,
            new PolymorphicWithoutIndex,
            new ForeignKeyWithoutIndex,
            new DuplicateIndex,
            new RedundantPrefixIndex,
            new IndexDuplicatingPrimaryKey,
            new UnindexedSoftDelete,
            new MissingUniqueConstraint,
            new MoneyAsFloat,
            new BooleanAsString,
        );
    }

    /**
     * @return list<Rule>
     */
    public function all(): array
    {
        return $this->rules;
    }

    /**
     * The rules to run.
     *
     * The preset picks a baseline ("recommended" runs high-confidence rules only,
     * "strict" runs everything, "none" runs nothing), an explicit enable/disable
     * per code overrides that baseline (so a heuristic rule can be switched on, or
     * a rule switched off), and --only/--skip narrow by category last.
     *
     * @param  'recommended'|'strict'|'none'  $preset
     * @param  list<string>  $only  category tokens to keep
     * @param  list<string>  $skip  category tokens to drop
     * @param  array<string, bool>  $enabled  rule code => explicitly on/off
     * @return list<Rule>
     */
    public function resolve(string $preset = 'recommended', array $only = [], array $skip = [], array $enabled = []): array
    {
        return array_values(array_filter(
            $this->rules,
            fn (Rule $rule): bool => $this->isActive($rule, $preset, $enabled)
                && $this->passesCategoryFilters($rule, $only, $skip),
        ));
    }

    /**
     * @param  array<string, bool>  $enabled
     */
    private function isActive(Rule $rule, string $preset, array $enabled): bool
    {
        if (array_key_exists($rule->code(), $enabled)) {
            return $enabled[$rule->code()];
        }

        return match ($preset) {
            'strict' => true,
            'recommended' => $rule->confidence() === Confidence::High,
            default => false,
        };
    }

    /**
     * @param  list<string>  $only
     * @param  list<string>  $skip
     */
    private function passesCategoryFilters(Rule $rule, array $only, array $skip): bool
    {
        $category = $rule->category()->value;

        if ($only !== [] && ! in_array($category, $only, true)) {
            return false;
        }

        return ! in_array($category, $skip, true);
    }
}
