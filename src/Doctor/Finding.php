<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor;

/**
 * One structural problem a rule found. Structure only: it names the table and
 * (optionally) column, never any row data.
 *
 * `message` says what is wrong, `hint` says why it matters, `suggestion` is an
 * optional migration snippet or null.
 */
final readonly class Finding
{
    public function __construct(
        public string $code,          // e.g. "TRUSS-IDX-001"
        public Severity $severity,
        public string $connection,
        public string $table,
        public ?string $column,       // or an index / constraint name, or null
        public string $message,
        public string $hint,
        public ?string $suggestion = null,
    ) {}

    /**
     * A stable identity for the suppression file. Built from code, connection,
     * table, and column only, so re-wording a message never invalidates a
     * suppression, and it does not depend on the order findings were produced.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->code,
            $this->connection,
            $this->table,
            $this->column ?? '',
        ]));
    }

    /**
     * A copy at a different severity, for the runner's per-rule overrides. Every
     * other field, and therefore the fingerprint, is unchanged.
     */
    public function withSeverity(Severity $severity): self
    {
        return new self(
            code: $this->code,
            severity: $severity,
            connection: $this->connection,
            table: $this->table,
            column: $this->column,
            message: $this->message,
            hint: $this->hint,
            suggestion: $this->suggestion,
        );
    }
}
