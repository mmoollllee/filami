<?php

/**
 * <x-filami::tracking /> must be safe to drop into any layout: it renders
 * nothing when disabled, without an id, or outside the allowed environments.
 */

use Illuminate\Support\Facades\Blade;
use Mmoollllee\Filami\Tests\Fixtures\Site;

it('renders the tracker script with preconnect hints', function () {
    configureUmami(['tracking' => ['environments' => ['testing']]]);

    $html = Blade::render('<x-filami::tracking website-id="w-1" data-domains="acme.test" fetchpriority="low" />');

    expect($html)
        ->toContain('src="https://a.example.test/script.js"')
        ->toContain('data-website-id="w-1"')
        ->toContain('data-domains="acme.test"')
        ->toContain('fetchpriority="low"')
        ->toContain('rel="preconnect"')
        ->toContain('//a.example.test');
});

it('renders nothing outside the allowed environments', function () {
    configureUmami(); // default: production only, tests run in "testing"

    expect(trim(Blade::render('<x-filami::tracking website-id="w-1" />')))->toBe('');
});

it('renders nothing without a website id', function () {
    configureUmami(['tracking' => ['environments' => ['*']]]);

    expect(trim(Blade::render('<x-filami::tracking />')))->toBe('');
});

it('renders nothing without a configured url', function () {
    config()->set('filami.url', null);
    config()->set('filami.tracking.environments', ['*']);

    expect(trim(Blade::render('<x-filami::tracking website-id="w-1" />')))->toBe('');
});

it('resolves the id from a model', function () {
    configureUmami(['tracking' => ['environments' => ['*']]]);

    $site = Site::create(['name' => 'Acme', 'umami_website_id' => 'w-model']);

    $html = Blade::render('<x-filami::tracking :for="$site" />', ['site' => $site]);

    expect($html)->toContain('data-website-id="w-model"');
});

it('never borrows the configured id for an unprovisioned model', function () {
    // Otherwise this tenant's pageviews land in another tenant's website.
    configureUmami(['tracking' => ['environments' => ['*']], 'website_id' => 'other-tenant']);

    $site = Site::create(['name' => 'Acme']);

    $html = Blade::render('<x-filami::tracking :for="$site" />', ['site' => $site]);

    expect(trim($html))->toBe('');
});

it('uses the configured id when no model is named', function () {
    configureUmami(['tracking' => ['environments' => ['*']], 'website_id' => 'single-site']);

    expect(Blade::render('<x-filami::tracking />'))->toContain('data-website-id="single-site"');
});

it('honors a renamed tracker script', function () {
    configureUmami(['tracking' => ['script_name' => 'insights.js', 'environments' => ['testing']]]);

    expect(Blade::render('<x-filami::tracking website-id="w-1" />'))
        ->toContain('src="https://a.example.test/insights.js"');
});
