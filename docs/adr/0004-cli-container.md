# ADR 0004: Boot a minimal Illuminate container for the CLI, and carry a helper shim

- **Status:** Accepted 06/10/2026. Implemented in `src/Cli/Bootstrapper.php`, unreleased.
- **Verified:** `tests/Cli/` boots the container with no Testbench and no application, reads a real SQLite schema through the `Schema` facade, and resolves the HTML export's view namespace.

## Context

The framework-free binary has to run the code the package already has.
`src/` makes 53 `config()` calls and reads the schema through the `Schema`,
`DB`, `Cache` and `Log` facades. Two routes exist.

**Remove the expectation.** Extract a `SchemaReader` interface, push Laravel out
of the introspection layer, and give the CLI its own implementation. This was
the first sketch, and it prices a large refactor of working, tested code against
a benefit the user never sees.

**Satisfy the expectation.** Build a container holding exactly the services
those calls reach for, and call the same services the artisan commands call.
The Illuminate components are designed to run standalone, everything lands
inside the PHAR, and no installer's dependency tree is touched because the whole
console layer is `export-ignore`d.

The second is chosen. The `SchemaReader` extraction remains worth doing if the
goal ever becomes a PHAR with no Illuminate in it at all, but it is an
optimisation rather than a prerequisite, and it must not be allowed to gate this
work.

## Decision

`Bootstrapper::boot()` returns an `Illuminate\Container\Container` holding
`config`, `events`, `log`, `cache`, `db`, `db.schema`, `files` and `view`, with
the facades pointed at it. **Four parts of that are decisions rather than
plumbing.**

**The container is registered as the global instance.** `app()` and `config()`
both resolve through `Container::getInstance()`, so without
`Container::setInstance()` every `config()` call in `src/` resolves against
nothing. It is not a convenience, it is load-bearing.

**A helper shim ships with the binary.** `config()` and `app()` are defined in
`Illuminate/Foundation/helpers.php`, and **`Illuminate/Foundation` has no
`composer.json` in the framework tree**, so unlike Cache, Config, Database,
Events and View it is not published as a component and cannot be added to the
PHAR's manifest. Binding the container key `config` is therefore necessary and
not sufficient: the 53 calls in `src/` are calls to a *function* that would not
exist. The shim supplies both, delegating to
`Cli\Support\Helpers`, and **every definition is guarded with
`function_exists`** because a clone of this repository has laravel/framework
installed and an unguarded definition is a fatal redeclaration rather than a
test failure.

**The logic lives in a class, not in the function bodies.** In a development
clone Foundation always wins the function name, so a shim with its logic inline
could never be exercised by a test. A class is reachable in both worlds, and the
guard itself is tested by requiring the file while Foundation is loaded.

**No `Storage`, no disk, but `files` is required.** No CLI path reaches a
filesystem disk: `BaselineStore` is how the Laravel path fetches the left-hand
side of a diff, and a CLI comparing two live connections never records a
baseline. `files` is the opposite case and is bound, because `HtmlGenerator`
renders a Blade view and Blade needs a filesystem and a writable compiled path.
The assertion in the test suite is therefore negative on `filesystems` and
positive on `files`, which is one letter apart and two different components.

**The view stack is assembled by hand.** `ViewServiceProvider` calls
`terminating()` on the container, which exists on
`Illuminate\Foundation\Application` and not on a plain container. Nine lines of
explicit wiring buys independence from the one component that cannot be
required.

## Consequences

**A command runs the same code as its artisan twin**, which is what makes the
parity tests meaningful: they can only compare two callers of one
implementation, and this keeps there being one implementation.

**Compiled views land in `sys_get_temp_dir()`.** Inside a PHAR the package tree
is read-only, so a compiled path beside the views fails on first render rather
than at boot.

**The cache is an array store, so it is per-process.** One invocation reads one
database once, so a cache that outlives the process has nothing to offer and a
cache directory would be state the binary owns on a user's disk. A consequence
worth naming: `truss rebuild` has nothing durable to rebuild, which is why the
binary does not ship that command.

**The shim's presence in the PHAR cannot be proven by this test suite.** The
development clone always has Foundation, so the smoke lane that runs the built
PHAR is the only place the real wiring is exercised. A green suite here is not
evidence about the binary.

**Illuminate's internals are now a dependency of the boot sequence**, not just
of the code being booted. `CacheManager` reading `cache.stores`, the `Capsule`
writing `database.default` itself, `ViewServiceProvider` calling `terminating()`:
each was read from the installed source rather than assumed, and each can change
in a future major. The bootstrapper's unit tests are what turn such a change
into a red build instead of a broken binary.
