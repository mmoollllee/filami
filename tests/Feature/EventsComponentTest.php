<?php

/**
 * <x-filami::events /> installs the custom-event plumbing. It renders under the
 * same conditions as the tracker itself — no tracker, nothing to send events
 * to — and everything it installs is a no-op while window.umami is absent,
 * which is what keeps it correct behind a consent gate.
 */

use Illuminate\Support\Facades\Blade;

beforeEach(function () {
    configureUmami(['website_id' => 'w-1', 'tracking' => ['environments' => ['testing']]]);
});

it('installs the track helper and the livewire bridge', function () {
    $html = Blade::render('<x-filami::events />');

    expect($html)
        ->toContain('window.filami = { track: track };')
        ->toContain("window.addEventListener('filami-track'")
        // Absent tracker must be tolerated, not thrown on.
        ->toContain('! window.umami');
});

it('tracks phone and mail clicks by delegation', function () {
    $html = Blade::render('<x-filami::events />');

    expect($html)
        ->toContain("href.indexOf('tel:') === 0")
        ->toContain("href.indexOf('mailto:') === 0")
        ->toContain('"phone-click"')
        ->toContain('"email-click"')
        // Umami's own attribute wins, and data-umami-ignore opts out.
        ->toContain('data-umami-event')
        ->toContain('data-umami-ignore');
});

it('reports a form start once per form, not per field', function () {
    $html = Blade::render('<x-filami::events />');

    expect($html)
        ->toContain("form[data-filami-form]")
        ->toContain("track(name + '-start'")
        // Keyed by form name, not by a flag on the element: Livewire re-renders
        // the form on every keystroke and morphing loses element state.
        ->toContain('startedForms[name]');
});

it('cannot be blocked by a prototype-shaped form name', function () {
    // A bare {} used as a set reports "constructor"/"toString" as already
    // started off Object.prototype, so those forms never send their -start.
    expect(Blade::render('<x-filami::events />'))
        ->toContain('Object.create(null)')
        ->not->toContain('var startedForms = {}');
});

it('re-arms the form set on livewire navigation', function () {
    // The document listener survives a wire:navigate body swap, so without a
    // reset only the first page of an SPA session would report a start.
    expect(Blade::render('<x-filami::events />'))
        ->toContain("addEventListener('livewire:navigated'");
});

it('tracks obfuscated contact links without umami touching the click', function () {
    // Umami's own data-umami-event handler preventDefault()s anchor clicks and
    // then forces location.href — on a spamprotect href="#" link that races
    // the handler decrypting the address. Hence a filami-owned attribute.
    expect(Blade::render('<x-filami::events />'))
        ->toContain('a[data-filami-event]')
        ->toContain("getAttribute('data-filami-event')");
});

it('never hands umami an array as event data', function () {
    // PHP array_filter() on a fully-filtered payload yields [], which encodes
    // to a JSON array; `data || {}` cannot catch it because [] is truthy.
    expect(Blade::render('<x-filami::events />'))
        ->toContain('Array.isArray(data)');
});

it('omits the form listener when switched off', function () {
    configureUmami([
        'website_id' => 'w-1',
        'tracking' => ['environments' => ['testing']],
        'events' => ['forms' => false],
    ]);

    expect(Blade::render('<x-filami::events />'))
        ->not->toContain('form[data-filami-form]')
        // The link tracking is a separate switch and must survive.
        ->toContain('"phone-click"');
});

it('omits the link listener when switched off', function () {
    configureUmami([
        'website_id' => 'w-1',
        'tracking' => ['environments' => ['testing']],
        'events' => ['links' => false],
    ]);

    expect(Blade::render('<x-filami::events />'))
        ->not->toContain('"phone-click"')
        ->toContain('window.filami');
});

it('lets the prop override the configured link tracking', function () {
    // Unbound attribute: the string "false" must switch tracking OFF, which is
    // exactly what PHP's non-strict (bool) cast would get wrong.
    expect(Blade::render('<x-filami::events links="false" />'))
        ->not->toContain('"phone-click"');
});

it('renames events from config', function () {
    configureUmami([
        'website_id' => 'w-1',
        'tracking' => ['environments' => ['testing']],
        'events' => ['phone_event' => 'anruf', 'email_event' => 'mail'],
    ]);

    expect(Blade::render('<x-filami::events />'))
        ->toContain('"anruf"')
        ->toContain('"mail"');
});

it('renders nothing while the tracker does not', function () {
    config()->set('filami.tracking.environments', ['production']); // tests run in "testing"

    expect(trim(Blade::render('<x-filami::events />')))->toBe('');
});

it('renders nothing without a website id', function () {
    config()->set('filami.website_id', null);

    expect(trim(Blade::render('<x-filami::events />')))->toBe('');
});

it('tracks clicks that leave the site', function () {
    $html = Blade::render('<x-filami::events />');

    expect($html)
        ->toContain('"outbound-click"')
        // Resolved protocol/hostname rather than href patterns: relative hrefs
        // and "#anchor" report the current host and are excluded for free.
        ->toContain("link.protocol === 'http:'")
        ->toContain('link.hostname === window.location.hostname')
        ->toContain('target: link.hostname');
});

it('treats the configured own domains as internal', function () {
    configureUmami(['events' => ['internal_domains' => ['jobs.acme.test', 'shop.acme.test']]]);

    expect(Blade::render('<x-filami::events />'))
        ->toContain('["jobs.acme.test","shop.acme.test"]')
        ->toContain('internalHosts.indexOf(link.hostname)');
});

it('omits the outbound branch when switched off', function () {
    configureUmami(['events' => ['outbound' => false]]);

    expect(Blade::render('<x-filami::events />'))
        ->not->toContain('outbound-click')
        // The tel:/mailto: half is a separate switch and must survive.
        ->toContain('"phone-click"');
});

it('still installs the click listener when only outbound is on', function () {
    configureUmami(['events' => ['links' => false]]);

    expect(Blade::render('<x-filami::events />'))
        ->toContain('"outbound-click"')
        ->not->toContain('"phone-click"')
        ->not->toContain('data-filami-event');
});

it('renames the outbound event from config', function () {
    configureUmami(['events' => ['outbound_event' => 'externer-link']]);

    expect(Blade::render('<x-filami::events />'))->toContain('"externer-link"');
});
