<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\DoctorRunner;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

// A rule that simply emits the findings it is handed, for exercising the runner
// without a real rule or a database.
function stubRule(string $code, array $findings, Category $category = Category::Index, Confidence $confidence = Confidence::High): Rule
{
    return new class($code, $findings, $category, $confidence) implements Rule
    {
        /** @param list<Finding> $findings */
        public function __construct(
            private string $code,
            private array $findings,
            private Category $category,
            private Confidence $confidence,
        ) {}

        public function code(): string
        {
            return $this->code;
        }

        public function category(): Category
        {
            return $this->category;
        }

        public function confidence(): Confidence
        {
            return $this->confidence;
        }

        public function defaultSeverity(): Severity
        {
            return Severity::Warning;
        }

        public function title(): string
        {
            return 'Stub rule';
        }

        public function check(array $snapshot, string $connection): iterable
        {
            return $this->findings;
        }
    };
}

function runnerFinding(string $code, string $table, ?string $column = null, Severity $severity = Severity::Warning): Finding
{
    return new Finding($code, $severity, 'mysql', $table, $column, 'a message', 'a hint');
}

it('collects findings from every rule and returns them sorted', function () {
    $rules = [
        stubRule('TRUSS-A', [runnerFinding('TRUSS-A', 'zebra', severity: Severity::Info)]),
        stubRule('TRUSS-B', [runnerFinding('TRUSS-B', 'apples', severity: Severity::Error)]),
    ];

    $result = (new DoctorRunner)->run($rules, [], 'mysql');

    expect(array_map(fn (Finding $f): string => $f->code, $result->all()))->toBe(['TRUSS-B', 'TRUSS-A']);
});

it('applies a per-code severity override', function () {
    $rule = stubRule('TRUSS-IDX-001', [runnerFinding('TRUSS-IDX-001', 'posts', 'user_id')]);

    $result = (new DoctorRunner)->run([$rule], [], 'mysql', severityOverrides: ['TRUSS-IDX-001' => Severity::Error]);

    expect($result->all()[0]->severity)->toBe(Severity::Error);
});

it('drops findings matching an ignore pattern by table', function () {
    $rule = stubRule('TRUSS-NAM-001', [
        runnerFinding('TRUSS-NAM-001', 'legacy_imports'),
        runnerFinding('TRUSS-NAM-001', 'users'),
    ]);

    $result = (new DoctorRunner)->run([$rule], [], 'mysql', ignore: ['TRUSS-NAM-001' => ['legacy_*']]);

    expect(array_map(fn (Finding $f): string => $f->table, $result->all()))->toBe(['users']);
});

it('drops findings matching an ignore pattern by table.column', function () {
    $rule = stubRule('TRUSS-IDX-001', [
        runnerFinding('TRUSS-IDX-001', 'audit_log', 'actor_id'),
        runnerFinding('TRUSS-IDX-001', 'posts', 'user_id'),
    ]);

    $result = (new DoctorRunner)->run([$rule], [], 'mysql', ignore: ['TRUSS-IDX-001' => ['audit_log.actor_id']]);

    expect(array_map(fn (Finding $f): string => "{$f->table}.{$f->column}", $result->all()))->toBe(['posts.user_id']);
});

it('only ignores under the matching rule code', function () {
    // An ignore for one code must not silence a different code on the same table.
    $rule = stubRule('TRUSS-IDX-001', [runnerFinding('TRUSS-IDX-001', 'legacy_imports', 'x')]);

    $result = (new DoctorRunner)->run([$rule], [], 'mysql', ignore: ['TRUSS-NAM-001' => ['legacy_*']]);

    expect($result)->toHaveCount(1);
});

it('returns an empty collection when no rule produces a finding', function () {
    expect((new DoctorRunner)->run([stubRule('TRUSS-A', [])], [], 'mysql')->isEmpty())->toBeTrue();
});
