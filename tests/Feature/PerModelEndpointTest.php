<?php

/**
 * A model may carry its own Umami endpoint. Together with a website id that is
 * everything tracking needs, so filami can be used purely from the UI without
 * touching .env — credentials stay in env and only gate the API features.
 */

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\Tests\Fixtures\Site;
use Mmoollllee\Filami\Tests\Fixtures\TrackedSite;

it('tracks a model that carries endpoint and id, with no env at all', function () {
    config()->set('filami.url', null);
    config()->set('filami.website_id', null);
    config()->set('filami.tracking.environments', ['*']);

    $site = Site::create([
        'name' => 'Acme',
        'umami_url' => 'https://stats.acme.test',
        'umami_website_id' => 'w-acme',
    ]);

    expect(Filami::enabled($site))->toBeTrue()
        ->and(Filami::tracks($site))->toBeTrue()
        ->and(Filami::url($site))->toBe('https://stats.acme.test');

    expect(Blade::render('<x-filami::tracking :for="$site" />', ['site' => $site]))
        ->toContain('src="https://stats.acme.test/script.js"')
        ->toContain('data-website-id="w-acme"');
});

it('stays inert for a model without an endpoint when none is configured', function () {
    config()->set('filami.url', null);
    config()->set('filami.tracking.environments', ['*']);

    $site = Site::create(['name' => 'Acme', 'umami_website_id' => 'w-acme']);

    expect(Filami::enabled($site))->toBeFalse()
        ->and(trim(Blade::render('<x-filami::tracking :for="$site" />', ['site' => $site])))->toBe('');
});

it('lets a model override the configured endpoint', function () {
    configureUmami(); // https://a.example.test
    config()->set('filami.tracking.environments', ['*']);

    $own = Site::create(['name' => 'Own', 'umami_url' => 'https://own.test', 'umami_website_id' => 'w-1']);
    $shared = Site::create(['name' => 'Shared', 'umami_website_id' => 'w-2']);

    expect(Filami::url($own))->toBe('https://own.test')
        ->and(Filami::url($shared))->toBe('https://a.example.test')
        ->and(Filami::apiUrl($own))->toBe('https://own.test/api');

    expect(Blade::render('<x-filami::tracking :for="$s" />', ['s' => $own]))
        ->toContain('https://own.test/script.js');
});

it('trims a trailing slash and builds the dashboard link per model', function () {
    configureUmami();

    $site = Site::create(['name' => 'Own', 'umami_url' => 'https://own.test/']);

    expect(Filami::url($site))->toBe('https://own.test')
        ->and(Filami::websiteDashboardUrl('w-9', $site))->toBe('https://own.test/websites/w-9')
        ->and(Filami::websiteDashboardUrl('w-9'))->toBe('https://a.example.test/websites/w-9');
});

it('resolves the endpoint through the contract too', function () {
    configureUmami();

    $site = TrackedSite::create([
        'title' => 'Legacy',
        'host' => 'legacy.test',
        'analytics_id' => 'w-legacy',
        'endpoint' => 'https://legacy-stats.test',
    ]);

    expect(Filami::url($site))->toBe('https://legacy-stats.test');
});

it('provisions against the model own instance', function () {
    configureUmami();
    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites*' => fn ($request) => $request->method() === 'POST'
            ? Http::response(['id' => 'w-own'])
            : Http::response(['data' => []]),
    ]);
    Filami::autoProvision(Site::class);

    $site = Site::create([
        'name' => 'Own',
        'primary_domain' => 'own.test',
        'umami_url' => 'https://own-instance.test',
    ]);

    expect($site->fresh()->umami_website_id)->toBe('w-own');

    // Every call went to the model's instance, never the configured default.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'a.example.test'));
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://own-instance.test/api'));
});

it('keeps requiring credentials for the api even with a model endpoint', function () {
    config()->set('filami.url', null);
    config()->set('filami.username', null);
    config()->set('filami.password', null);

    $site = Site::create(['name' => 'Acme', 'umami_url' => 'https://own.test']);

    expect(Filami::enabled($site))->toBeTrue()
        ->and(Filami::apiConfigured($site))->toBeFalse();
});
