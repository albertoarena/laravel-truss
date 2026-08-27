<?php

declare(strict_types=1);

use AlbertoArena\Truss\Commands\ExportCommand;

/**
 * The Boost skill is the workflow content that does not belong in standing
 * context: Boost loads it only when a task is actually about the database.
 *
 * Its front matter is load-bearing and fails in silence. Boost keys a skill by
 * the `name` in that front matter, not by its directory, and drops the skill
 * entirely if `name` or `description` is missing or empty, with no error, no
 * warning and no exit code. Verified against Boost v2.5.4 by deleting the line
 * and watching the skill vanish.
 */
$skillDir = __DIR__.'/../../../resources/boost/skills/truss-schema';

$skill = fn (): string => (string) file_get_contents(
    __DIR__.'/../../../resources/boost/skills/truss-schema/SKILL.md'
);

/** Extract the raw front matter block the way Boost's own parser does. */
$frontMatter = function () use ($skill): string {
    preg_match('/^\s*---\s*\n(.*?)\n---\s*\n/s', $skill(), $matches);

    return $matches[1] ?? '';
};

it('ships one skill, at the path Boost scans', function () {
    $dirs = glob(__DIR__.'/../../../resources/boost/skills/*', GLOB_ONLYDIR) ?: [];

    expect(array_map('basename', $dirs))->toBe(['truss-schema']);
});

it('ships the skill as SKILL.md', function () use ($skillDir) {
    expect($skillDir.'/SKILL.md')->toBeReadableFile();
});

it('does not ship a Blade skill beside it', function () use ($skillDir) {
    // Boost checks SKILL.blade.php before SKILL.md, so a Blade file appearing
    // later would silently take over, and its rendering failures surface inside
    // someone else's boost:install with our package named.
    expect($skillDir.'/SKILL.blade.php')->not->toBeFile();
});

it('opens with front matter, with nothing before it', function () use ($frontMatter, $skill) {
    // Boost strips leading HTML comments and then requires the fence at the
    // start. Any other preamble means no front matter, which means no skill.
    expect($skill())->toStartWith('---');
    expect($frontMatter())->not->toBeEmpty();
});

it('carries the name Boost keys the skill by', function () use ($frontMatter, $skillDir) {
    // The name is the identifier: it keys the skill globally across every
    // package, and it is what a user puts in `boost.skills.exclude`. Renaming
    // it orphans every existing exclusion, so it is pinned to the directory.
    preg_match('/^name:\s*(\S+)\s*$/m', $frontMatter(), $matches);

    expect($matches[1] ?? '')
        ->toBe('truss-schema')
        ->toBe(basename($skillDir));
});

it('carries a description that says when to reach for it', function () use ($frontMatter) {
    // An empty description drops the skill as surely as a missing name, and the
    // description is the text an agent matches a task against, so it names the
    // situations rather than describing the tool.
    preg_match('/^description:\s*(.+)$/m', $frontMatter(), $matches);

    expect(trim($matches[1] ?? ''))
        ->not->toBeEmpty()
        ->toContain('Use when');
});

it('states the structure-only boundary', function () use ($skill) {
    // Same rule as the guideline: the description is the text an agent matches
    // against and then repeats, so it closes on the canonical brand line rather
    // than a near-miss of it. "Structure only, never row data" shipped here
    // after the guideline had already been corrected off exactly that variant.
    expect($skill())
        ->toContain('Structure only, never data.')
        ->not->toContain('never row data');

    // And in plain prose too, because the tagline alone does not tell an agent
    // what not to reach for.
    expect($skill())->toContain('It never returns row data');
});

it('teaches the workflow in order', function () use ($skill) {
    // The guideline says Truss exists and lists commands. The skill is the
    // sequence: read the structure, check it, change it, confirm the change.
    expect($skill())
        ->toContain('truss:export')
        ->toContain('truss:doctor')
        ->toContain('truss:diff');
});

it('offers a doctor fix rather than folding it in unasked', function () use ($skill) {
    // The skill told the agent to fix any finding on a table it was already
    // touching, in the same migration. Asked for one column, an agent would
    // ship an index and a column-type change too, against a real database,
    // with "you are already there" as the reasoning. Scope creep that feels
    // responsible is still scope creep, and a migration is hard to walk back.
    // Reporting is the agent's job here; deciding is the user's.
    // Matched against whitespace-normalised prose: these are sentences, and a
    // sentence that reflows across a line break is the same instruction. A test
    // that fails on rewrapping teaches you to stop rewrapping.
    $prose = (string) preg_replace('/\s+/', ' ', $skill());

    expect($prose)
        ->toContain('Do not fold it in unasked')
        ->not->toContain('because you are already there');

    // The other half was always right: a finding elsewhere is not this task.
    expect($prose)->toContain('leave it alone and say so');
});

it('carries the reference detail that left standing context', function () use ($skill) {
    // The formats and --check moved here from the guideline. The skill loads
    // only when a task is about the database, so this costs nothing per
    // request, but it has to actually land somewhere or the cut lost it.
    expect($skill())
        ->toContain('--format=dbml')
        ->toContain('--output=')
        ->toContain('--check');
});

it('names only flags that exist on the export command', function () use ($skill) {
    $signature = (new ReflectionClass(ExportCommand::class))
        ->getDefaultProperties()['signature'];

    preg_match_all('/--([a-z][a-z-]*)/', $skill(), $matches);

    expect(array_unique($matches[1]))->not->toBeEmpty();

    foreach (array_unique($matches[1]) as $flag) {
        expect($signature)->toContain('--'.$flag);
    }
});

it('uses no em or en dashes', function () use ($skill) {
    expect($skill())
        ->not->toContain('—')
        ->not->toContain('–');
});
