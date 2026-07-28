<?php

use Illuminate\Support\Facades\Http;
use Mmoollllee\Filami\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/** Minimal working API config so Filami::apiConfigured() is true. */
function configureUmami(array $overrides = []): void
{
    config()->set('filami', array_replace_recursive(config('filami'), [
        'url' => 'https://a.example.test',
        'username' => 'api',
        'password' => 'secret',
    ], $overrides));
}

/**
 * Fakes the login plus the websites collection. Provisioning looks a domain up
 * before creating, so the GET and the POST need separate answers; $existing
 * seeds the lookup to exercise the adopt path.
 *
 * @param  list<array<string, mixed>>  $existing
 */
function fakeUmamiWebsites(array $created = ['id' => 'uuid-1'], array $existing = []): void
{
    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites*' => fn ($request) => $request->method() === 'POST'
            ? Http::response($created)
            : Http::response(['data' => $existing]),
    ]);
}
