<?php

declare(strict_types=1);

use AlbertoArena\Truss\Http\Middleware\Authorize;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

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
