<?php

/**
 * Website id / metadata resolution: UmamiTrackable models answer for
 * themselves, everything else falls back to the attribute conventions. The
 * HasUmamiWebsite trait must stay indistinguishable from that fallback —
 * having the rules written twice is how the two quietly drift apart.
 */

use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\Tests\Fixtures\ConventionalSite;
use Mmoollllee\Filami\Tests\Fixtures\Site;
use Mmoollllee\Filami\Tests\Fixtures\TrackedSite;

it('resolves via attribute conventions', function () {
    $site = Site::create([
        'name' => 'Acme',
        'primary_domain' => 'acme.test',
        'umami_website_id' => 'w-1',
    ]);

    expect(Filami::websiteId($site))->toBe('w-1')
        ->and(Filami::websiteMeta($site))->toBe(['name' => 'Acme', 'domain' => 'acme.test']);
});

it('stores via attribute conventions', function () {
    $site = Site::create(['name' => 'Acme', 'primary_domain' => 'acme.test']);

    Filami::storeWebsiteId($site, 'w-2');

    expect($site->fresh()->umami_website_id)->toBe('w-2');
});

it('derives the domain from a url attribute', function () {
    $site = new Site(['name' => 'Acme']);
    $site->setAttribute('url', 'https://www.acme.test/some/path');

    expect(Filami::websiteMeta($site)['domain'])->toBe('www.acme.test');
});

it('resolves via the contract with overridden columns', function () {
    $site = TrackedSite::create([
        'title' => 'Nest',
        'host' => 'nest.test',
        'analytics_id' => 'legacy-1',
    ]);

    expect(Filami::websiteId($site))->toBe('legacy-1')
        ->and(Filami::websiteMeta($site))->toBe(['name' => 'Nest', 'domain' => 'nest.test']);

    Filami::storeWebsiteId($site, 'legacy-2');

    expect($site->fresh()->analytics_id)->toBe('legacy-2');
});

it('resolves a trait-only model exactly like the plain fallback', function () {
    $attributes = ['name' => 'Acme', 'primary_domain' => 'acme.test', 'umami_website_id' => 'w-1'];

    $plain = Site::create($attributes);
    $withTrait = ConventionalSite::create($attributes);

    expect(Filami::websiteId($withTrait))->toBe(Filami::websiteId($plain))
        ->and(Filami::websiteMeta($withTrait))->toBe(Filami::websiteMeta($plain));
});

it('stores through the trait exactly like the plain fallback', function () {
    $site = ConventionalSite::create(['name' => 'Acme', 'primary_domain' => 'acme.test']);

    Filami::storeWebsiteId($site, 'w-2');

    expect($site->fresh()->umami_website_id)->toBe('w-2');
});

it('falls back to the configured id only without a model', function () {
    config()->set('filami.website_id', 'single-site');

    $unprovisioned = Site::create(['name' => 'Acme']);

    expect(Filami::websiteIdFor(null))->toBe('single-site')
        ->and(Filami::websiteIdFor($unprovisioned))->toBeNull();
});
