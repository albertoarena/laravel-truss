<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Introspection\Data;

/**
 * A foreign key. Both the local `columns` and the referenced `referencesColumns`
 * are arrays so composite foreign keys are first-class. `onUpdate`/`onDelete`
 * carry the referential actions (structure, not data) for display.
 */
final readonly class ForeignKey
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencesColumns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencesTable,
        public array $referencesColumns,
        public ?string $onUpdate,
        public ?string $onDelete,
    ) {}
}
