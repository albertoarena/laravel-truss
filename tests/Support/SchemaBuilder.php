<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Tests\Support;

/**
 * Builds a schema snapshot in memory, without a database, in the exact array
 * shape SchemaSerializer produces, so doctor rules can be unit tested against
 * realistic input. A contract test (tests/Feature/Doctor) pins this shape to
 * what real introspection returns, so the fixture cannot drift from reality.
 */
final class SchemaBuilder
{
    /** @var list<array<string, mixed>> */
    private array $tables = [];

    public function __construct(private readonly string $connection = 'testing') {}

    public static function make(string $connection = 'testing'): self
    {
        return new self($connection);
    }

    /**
     * @param  (callable(TableDraft): mixed)|null  $define
     */
    public function table(string $name, ?callable $define = null): self
    {
        $draft = new TableDraft;

        if ($define !== null) {
            $define($draft);
        }

        $this->tables[] = $draft->toArray($name);

        return $this;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'connection' => $this->connection,
            'fallback' => false,
            'skipped_migrations' => [],
            'tables' => $this->tables,
        ];
    }
}
