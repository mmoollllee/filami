<?php

/**
 * filami:sync backfills records that existed before the integration and
 * optionally pushes metadata for records that are already linked.
 */

use Illuminate\Support\Facades\Http;
use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\Tests\Fixtures\Site;

it('provisions missing websites synchronously', function () {
    configureUmami();
    fakeUmamiWebsites(created: ['id' => 'new-1', 'name' => 'A', 'domain' => 'a.test']);
    Filami::autoProvision(Site::class);

    $missing = Site::withoutEvents(fn () => Site::create(['name' => 'A', 'primary_domain' => 'a.test']));
    $linked = Site::withoutEvents(fn () => Site::create(['name' => 'B', 'primary_domain' => 'b.test', 'umami_website_id' => 'w-b']));

    $this->artisan('filami:sync')->assertSuccessful();

    expect($missing->fresh()->umami_website_id)->toBe('new-1');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/websites/w-b'));
});

it('pushes linked records with --push', function () {
    configureUmami();
    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-b' => Http::response(['id' => 'w-b', 'name' => 'B', 'domain' => 'b.test']),
    ]);
    Filami::autoProvision(Site::class);

    Site::withoutEvents(fn () => Site::create(['name' => 'B', 'primary_domain' => 'b.test', 'umami_website_id' => 'w-b']));

    $this->artisan('filami:sync', ['--push' => true])->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/websites/w-b')
        && $request['name'] === 'B'
        && $request['domain'] === 'b.test');
});

it('skips records rejected by the when filter', function () {
    configureUmami();
    Http::fake(['*/api/auth/login' => Http::response(['token' => 't'])]);
    Filami::autoProvision(Site::class, when: fn (Site $site) => false);

    Site::withoutEvents(fn () => Site::create(['name' => 'A', 'primary_domain' => 'a.test']));

    $this->artisan('filami:sync')->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/websites'));
});

it('re-links ids the instance does not know when pushing', function () {
    // The rollout case: ids left over from a previous Umami instance would
    // otherwise be skipped forever and keep tracking into the void.
    configureUmami();
    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/stale-1' => Http::response(['message' => 'not found'], 404),
        '*/api/websites*' => fn ($request) => $request->method() === 'POST'
            ? Http::response(['id' => 'new-1'])
            : Http::response(['data' => []]),
    ]);
    Filami::autoProvision(Site::class);

    $site = Site::withoutEvents(fn () => Site::create([
        'name' => 'A',
        'primary_domain' => 'a.test',
        'umami_website_id' => 'stale-1',
    ]));

    $this->artisan('filami:sync', ['--push' => true])->assertSuccessful();

    expect($site->fresh()->umami_website_id)->toBe('new-1');
});

it('fails without configuration', function () {
    $this->artisan('filami:sync')->assertFailed();
});
