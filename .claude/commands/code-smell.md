---
description: Audit first-party source for code smells and architectural problems, writing verified findings to AUDIT.md
---

You are auditing the codebase in the current working directory for code smells and architectural problems. The goal is an audit the maintainers can act on. Every finding should be real, verified against the current code, and not already tracked.

Inputs. Use these defaults unless the user overrides them:
- Tracker file: `AUDIT.md` at the repo root. Create it if it's missing; add to new file if it exists.
- Scope: all first-party source. Exclude vendored, generated and build output (`vendor/`, `node_modules/`, `dist/`, `build/`, lockfiles, migrations' generated SQL, compiled assets).
- Changes allowed: the tracker file only. Don't edit source or tests, and don't commit.

## Before you start

Learn the project before judging it.

1. Read the guidance files that exist: `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md`, the README's architecture or contributing sections, and ADRs or `docs/architecture*`.
2. Read the linter, formatter and type-checker configs (for example `phpstan.neon`, `pint.json`, `.eslintrc*`, `tsconfig.json`, `pyproject.toml`, `rector.php`, `biome.json`). Their rules, and especially their ignores, show deliberate choices.
3. Read the dependency manifest (`composer.json`, `package.json`, `go.mod`, `Cargo.toml`, `pyproject.toml`) so you know the framework, and don't flag its idioms as smells.
4. Run `git log --oneline -20` and `git status` so you know what just changed and what is uncommitted. Trust only what you read in this session.
5. If the tracker file exists, read it in full. Anything already in it, including items marked won't-do or rejected and their reasons, is out of scope. Don't re-report it in different words.

From these, write down for yourself the project's deliberate conventions. Examples: "no final classes", "immutable value objects", "functions over classes", "errors as return values", "barrel files per module". Treat each one as a design decision, not a smell, but do report code that breaks it. When a convention is only implied by consistent practice across the codebase, follow it too.

Then map the structure: top-level modules or packages, entry points (HTTP routes, CLI commands, jobs, exported public API), and the rough size of each area.

## What to look for

**Smells.** Check the code against the refactoring.guru catalog. Translate each smell to the paradigm in use: in functional or module-based code, "class" means module or closure, "inheritance" includes composition hierarchies, and "switch statements" includes any branching on a type tag or discriminant.
- **Bloaters:** Long Method, Large Class, Primitive Obsession, Long Parameter List, Data Clumps.
- **Object-orientation abusers:** Switch Statements, Temporary Field, Refused Bequest, Alternative Classes with Different Interfaces.
- **Change preventers:** Divergent Change, Shotgun Surgery, Parallel Inheritance Hierarchies.
- **Dispensables:** Comments, Duplicate Code, Lazy Class, Data Class, Dead Code, Speculative Generality.
- **Couplers:** Feature Envy, Inappropriate Intimacy, Message Chains, Middle Man, Incomplete Library Class.

**Architecture.**
- **SOLID.** Units with more than one reason to change. Adding a variant (a new provider, format, handler or plugin) that requires editing core code (open/closed). Implementations that can't honour their contract. Interfaces that force members on implementers that don't need them. Core code that depends on concrete implementations or test doubles.
- **Boundaries and layering.** Whether dependencies point the right way. Transport, framework or vendor details leaking into domain logic. Global state and service locators used where injection is available. Circular imports.
- **Extension points.** If the project exposes plugins, drivers, hooks or a public API, check whether a third-party extension gets correct behaviour from the defaults, or silent no-ops and hard-coded lists of the built-in variants.
- **Consistency.** Sibling implementations that give the same method or concept different meanings (null versus empty, units, ID formats, error handling).
- **Behaviour.** A smell often hides a bug. Read the code paths end to end; where two layers disagree, work out what actually happens at runtime.

## How to work

Split the in-scope source into slices along module boundaries, sized so each is readable in full. Use about 3 slices for roughly 15k lines and add one per further 5–10k lines, up to 6. Give each slice to a read-only subagent, running in parallel. Give each subagent:
- the conventions you wrote down;
- what the tracker already covers;
- this checklist and the evidence standard below;
- its slice's file list.

Tell each one to read every file in its slice in full, and to read outside it only for cross-file context. A slice's findings should be rooted in that slice.

While they run, don't repeat their work. When they report back, you do the verification yourself:
- Open the cited lines for every P0 and P1 finding, and for any claim of dead code or a runtime bug.
- Check runtime claims with a one-liner in the project's language (`php -r`, `node -e`, `python -c`) or by reading the test that covers the path.
- Drop findings that don't hold up. Merge ones that several slices found separately, especially change preventers that span slices.

## Evidence standard

A finding is included only if all of these hold:
- It cites `path:line` from the current tree and quotes or names the code involved.
- It states a concrete consequence: a wrong result, a change that has to touch several named places, an API that callers will misuse, or code that can be deleted.
- Dead code has grep proof across source, tests, examples and docs. Allow for dynamic dispatch, reflection, framework conventions (magic methods, route or DI auto-wiring, exported public API) and string-based lookups before calling something unused.
- A duplication claim names every copy.
- A suggested fix names a refactoring.guru technique (Extract Function, Replace Conditional with Polymorphism, Introduce Parameter Object, and so on), fits the project's conventions, and adds no abstraction the problem doesn't need.

Fewer findings that are certain are worth more than many that are plausible. If something looks wrong but has a reason (framework constraints, serialization, performance, public-API stability, a won't-do entry), leave it out. Also leave out a smell whose fix only moves code around without making anything simpler, unless the unit is changing for several reasons at once.

## Output

Write the verified findings to the tracker file. If it already has a format, match it. Otherwise use this format:

```
# Code Audit

Tick an item when it lands, and note the commit next to it.

- P0: broken today, the behaviour is wrong.
- P1: misleading today, users or contributors will get it wrong.
- P2: inconsistent with siblings or other layers, or costly to change.
- P3: polish, duplication, dead code.

## P0: Broken
- [ ] 1. <problem in one or two sentences, naming the code>. <fix>.
```

Number items in one sequence across all sections, continuing from the highest existing number. Add `Needs a decision.` where a maintainer has to choose. Group P3 items under short subheadings (Duplicates to remove, Dead code, Primitive values, Splits).

Then reply in chat with:
- how many findings were added at each priority;
- the P0 items in one line each;
- the three changes that would delete the most code, with rough line counts;
- anything you dropped during verification, with one line on why.

## Finishing

The audit is finished when every slice has been read in full, every P0 and P1 finding has been checked against the source, and the tracker is written. If a subagent comes back with gaps, send it back or cover the gap yourself before writing the file. Ending with a summary that announces the next step is not finishing; do that step. Stop to ask only if something blocks the work and can't be settled from the code or the project's guidance files.
