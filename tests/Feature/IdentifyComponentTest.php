<?php

/**
 * <x-filami::identify /> names the visitor to Umami — a distinct id and session
 * data — under the same conditions the tracker renders, and waits for the
 * deferred tracker instead of calling into nothing.
 */

use Illuminate\Support\Facades\Blade;
use Mmoollllee\Filami\Tests\Fixtures\Site;

beforeEach(function () {
    configureUmami(['tracking' => ['environments' => ['testing']]]);
});

it('names the visitor once the tracker is there', function () {
    $html = Blade::render(
        '<x-filami::identify website-id="w-1" distinct-id="user-7" :session-data="$data" />',
        ['data' => ['customer' => 'Acme GmbH', 'seats' => 3]],
    );

    expect($html)
        ->toContain('var distinctId = "user-7";')
        ->toContain('var sessionData = {"customer":"Acme GmbH","seats":3};')
        // Deferred tracker: not before DOMContentLoaded, and a late one is looked for.
        ->toContain("addEventListener('DOMContentLoaded', start)")
        ->toContain('setInterval(');
});

it('sends session data alone when there is no distinct id', function () {
    expect(Blade::render('<x-filami::identify website-id="w-1" :session-data="[\'plan\' => \'pro\']" />'))
        ->toContain('var distinctId = null;')
        ->toContain('var sessionData = {"plan":"pro"};');
});

it('keeps session data flat and named', function () {
    $html = Blade::render(
        '<x-filami::identify website-id="w-1" distinct-id="user-7" :session-data="$data" />',
        ['data' => ['customer' => 'Acme', 'tags' => ['a', 'b'], 'none' => null, 0 => 'unnamed']],
    );

    expect($html)->toContain('var sessionData = {"customer":"Acme"};');
});

it('keeps a value from closing the script it is written into', function () {
    $html = Blade::render(
        '<x-filami::identify website-id="w-1" distinct-id="user-7" :session-data="$data" />',
        ['data' => ['customer' => '</script><script>alert(1)</script>']],
    );

    expect($html)->not->toContain('<script>alert(1)</script>');
});

it('renders nothing for nobody', function () {
    expect(trim(Blade::render('<x-filami::identify website-id="w-1" :distinct-id="null" :session-data="[]" />')))->toBe('');
});

it('renders nothing while the tracker does not', function () {
    config()->set('filami.tracking.environments', ['production']); // tests run in "testing"

    expect(trim(Blade::render('<x-filami::identify website-id="w-1" distinct-id="user-7" />')))->toBe('');
});

it('follows the model like the tracker', function () {
    $tracked = Site::create(['name' => 'Acme', 'umami_website_id' => 'w-model']);
    $unprovisioned = Site::create(['name' => 'Beta']);

    expect(Blade::render('<x-filami::identify :for="$site" distinct-id="user-7" />', ['site' => $tracked]))->toContain('"user-7"')
        ->and(trim(Blade::render('<x-filami::identify :for="$site" distinct-id="user-7" />', ['site' => $unprovisioned])))->toBe('');
});

it('forwards extra attributes such as a nonce to its script tag', function () {
    expect(Blade::render('<x-filami::identify website-id="w-1" distinct-id="user-7" nonce="abc" />'))
        ->toContain('<script nonce="abc">');
});
