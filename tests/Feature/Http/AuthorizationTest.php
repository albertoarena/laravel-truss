<?php

declare(strict_types=1);

use AlbertoArena\Truss\Http\Middleware\Authorize;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A minimal authenticatable whose `email` the shipped default gate inspects.
 * Using actingAs() with this avoids needing a real Eloquent user + table.
 */
function trussUser(string $email): Authenticatable
{
    return new class($email) implements Authenticatable
    {
        public function __construct(public string $email) {}

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 1;
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    };
}

it('does not consult the gate in the local environment', function () {
    app()->detectEnvironment(fn () => 'local');
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => false); // would deny in non-local...

    $this->get('/truss')->assertOk(); // ...but local is open, gate skipped
});

it('admits an allow-listed email in non-local via the shipped default gate', function () {
    config()->set('truss.enabled', true);
    config()->set('truss.authorization.allowed_emails', ['admin@acme.com']);

    // No gate override: the package default gate is what authorizes here.
    $this->actingAs(trussUser('admin@acme.com'))
        ->get('/truss')
        ->assertOk();
});

it('404s a user whose email is not allow-listed', function () {
    config()->set('truss.enabled', true);
    config()->set('truss.authorization.allowed_emails', ['admin@acme.com']);

    $this->actingAs(trussUser('nobody@acme.com'))
        ->get('/truss')
        ->assertNotFound();
});

it('404s a guest in non-local even when enabled', function () {
    config()->set('truss.enabled', true);
    config()->set('truss.authorization.allowed_emails', ['admin@acme.com']);

    $this->get('/truss')->assertNotFound();
});

it('404s (not 403) when the gate denies, keeping the dashboard invisible', function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => false);

    $this->get('/truss')->assertNotFound();
});

it('registers the configured auth middleware ahead of the Authorize guard', function () {
    $middleware = app('router')->getRoutes()->getByName('truss.index')->gatherMiddleware();

    expect($middleware)->toContain('web')->toContain(Authorize::class);
});

it('404s rather than erroring when the host binds no Gate at all', function () {
    // October CMS ships its own authentication and binds no Gate contract.
    // Resolving one raises BindingResolutionException, and an error page
    // confirms the dashboard exists to somebody who may not view it, which is
    // the single thing this middleware is written to avoid. Driven directly
    // rather than through $this->get(), because a test request rebuilds the
    // container and puts the binding back.
    config()->set('truss.enabled', true);
    app()->detectEnvironment(fn () => 'production');

    unset(app()[GateContract::class]);
    // The facade caches what it resolved earlier in this file, so removing the
    // binding alone leaves a working Gate behind and the test would pass with
    // or without the guard.
    Gate::clearResolvedInstances();

    expect(fn () => (new Authorize)->handle(
        Request::create('/truss'),
        fn ($request) => new Response,
    ))->toThrow(NotFoundHttpException::class);
});
