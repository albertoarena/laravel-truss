<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Export\Contracts\CommentReader;
use AlbertoArena\Truss\Export\DatabaseCommentReader;
use AlbertoArena\Truss\TrussManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use RuntimeException;

/**
 * The container the framework-free binary runs on.
 *
 * `src/` expects Laravel: it makes 53 `config()` calls and reads the schema
 * through the `Schema`, `DB`, `Cache` and `Log` facades. Rather than removing
 * that expectation, which would be a large refactor of working code for no
 * gain, this gives the code what it expects: a container holding exactly the
 * services those calls reach for, and nothing else.
 *
 * Three things are deliberate rather than incidental.
 *
 * **The container is registered as the global instance.** `app()` and
 * `config()` both resolve through `Container::getInstance()`, whether they come
 * from Illuminate's Foundation helpers in a clone or from this package's own
 * shim inside the PHAR, so without that static every `config()` call in `src/`
 * resolves against nothing.
 *
 * **There is no filesystem disk and no `Storage`.** No CLI path reaches one:
 * `BaselineStore` is how the Laravel path fetches the left-hand side of a diff,
 * and a CLI comparing two live connections never records a baseline. `files` is
 * the opposite case and is required, because Blade compiles the HTML export's
 * view.
 *
 * **The view stack is assembled by hand rather than through
 * `ViewServiceProvider`.** That provider calls `terminating()` on the
 * container, which exists on `Illuminate\Foundation\Application` and not on a
 * plain container, and Foundation is the one Illuminate component that is not
 * published separately. Nine lines of wiring here buys independence from it.
 */
final class Bootstrapper
{
    /**
     * The connection name every CLI run uses.
     *
     * One invocation reads one database, so the name is an implementation
     * detail rather than something a user chooses. It is fixed so that
     * `config('database.default')` and the snapshot cache keys agree.
     */
    public const CONNECTION = 'truss';

    /**
     * @param  array<string, mixed>  $connection  a parsed connection, as the DSN parser returns it
     */
    public static function boot(array $connection, ?string $compiledPath = null): Container
    {
        $container = new Container;
        Container::setInstance($container);

        $files = new Filesystem;
        $container->instance('files', $files);

        self::registerConfig($container, $compiledPath ?? self::defaultCompiledPath());
        self::registerEvents($container);
        self::registerLog($container);
        self::registerCache($container);
        self::registerDatabase($container, $connection);
        self::registerView($container, $files);
        self::registerPackage($container);

        // src/ reaches the schema through facades, so they resolve against this
        // container or not at all.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        return $container;
    }

    /**
     * The package's own config file, plus the minimum the Illuminate
     * components need to resolve. `config/truss.php` loads standalone because
     * `env()` lives in `illuminate/support` rather than in Foundation.
     */
    private static function registerConfig(Container $container, string $compiledPath): void
    {
        $views = dirname(__DIR__, 2).'/resources/views';

        $config = new ConfigRepository([
            'truss' => require dirname(__DIR__, 2).'/config/truss.php',
            'database' => ['default' => self::CONNECTION, 'connections' => []],
            // Array, not file: one invocation reads one database once, so a
            // cache that outlives the process has nothing to offer and a
            // cache directory would be state the binary owns on somebody's
            // disk. The snapshot is built fresh on every run.
            'cache' => ['default' => 'array', 'prefix' => 'truss', 'stores' => ['array' => ['driver' => 'array']]],
            'view' => ['paths' => [$views], 'compiled' => $compiledPath],
        ]);

        $container->instance('config', $config);
        $container->alias('config', ConfigRepository::class);
        $container->alias('config', ConfigContract::class);
    }

    private static function registerEvents(Container $container): void
    {
        $container->instance('events', new Dispatcher($container));
        $container->alias('events', DispatcherContract::class);
    }

    /**
     * Logging goes to stderr at warning and above, so a `--format=json` export
     * on stdout stays machine-readable while a cache or introspection warning
     * still reaches a human. `Log::debug()` calls in `src/` are dropped.
     */
    private static function registerLog(Container $container): void
    {
        $container->instance('log', new Logger(
            new Monolog('truss', [new StreamHandler('php://stderr', Level::Warning)]),
            $container->make('events'),
        ));
    }

    private static function registerCache(Container $container): void
    {
        $container->singleton('cache', fn (Container $c): CacheManager => new CacheManager($c));
        $container->alias('cache', CacheFactory::class);
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private static function registerDatabase(Container $container, array $connection): void
    {
        $capsule = new Capsule($container);
        $capsule->addConnection($connection, self::CONNECTION);

        // After the Capsule, never before: its constructor writes
        // database.default itself, as the literal string "default".
        $container->make('config')->set('database.default', self::CONNECTION);

        $container->instance('db', $capsule->getDatabaseManager());

        // Schema::connection() resolves through $app['db'], which the line
        // above binds. A bare Schema:: call goes to the facade's own accessor,
        // which is db.schema and which the Capsule does not bind.
        $container->bind('db.schema', fn (Container $c) => $c->make('db')->connection()->getSchemaBuilder());
    }

    private static function registerView(Container $container, Filesystem $files): void
    {
        $config = $container->make('config');

        $blade = new BladeCompiler($files, $config->get('view.compiled'));

        $resolver = new EngineResolver;
        $resolver->register('blade', fn (): CompilerEngine => new CompilerEngine($blade, $files));
        $resolver->register('php', fn (): PhpEngine => new PhpEngine($files));

        $finder = new FileViewFinder($files, $config->get('view.paths'));

        $factory = new ViewFactory($resolver, $finder, $container->make('events'));
        $factory->setContainer($container);

        // The private namespace the HTML export renders. A published copy of
        // the dashboard view must not be able to take its place, or an export
        // comes out with route() URLs and no inlined assets.
        $factory->addNamespace('truss-package', $config->get('view.paths'));

        $container->instance('blade.compiler', $blade);
        $container->instance('view', $factory);
        $container->alias('view', ViewFactoryContract::class);
    }

    /**
     * The three bindings `TrussServiceProvider::packageRegistered()` makes,
     * repeated here because the binary has no provider to make them.
     *
     * Kept deliberately identical, including `scoped` rather than `singleton`
     * for the cache repository. One CLI invocation is one scope, so the two
     * behave the same here, and matching the provider means a reader comparing
     * the two surfaces finds the same three lines rather than a variation to
     * reason about.
     */
    private static function registerPackage(Container $container): void
    {
        // The DB-comment source behind the export Annotator.
        $container->bind(CommentReader::class, DatabaseCommentReader::class);

        // Shared, because whoever reads the snapshot and whoever reports on it
        // need the same lastError(). Resolve a second repository and a cache
        // outage becomes silence: the command asks an object that never read
        // anything why the read failed.
        $container->scoped(SchemaCacheRepository::class);

        // The entry point behind the facade, and what the commands build on.
        $container->singleton(TrussManager::class);
    }

    /**
     * Blade needs somewhere to write compiled views, and inside a PHAR the
     * package tree is read-only, so the path cannot live beside the views.
     */
    private static function defaultCompiledPath(): string
    {
        $path = sys_get_temp_dir().'/truss-views';

        if (! is_dir($path) && ! @mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new RuntimeException("Truss could not create a directory for compiled views at [{$path}].");
        }

        return $path;
    }
}
