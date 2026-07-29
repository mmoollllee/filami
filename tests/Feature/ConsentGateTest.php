<?php

/**
 * With a consent category configured the script tags are emitted inert —
 * type="text/plain" plus a marker attribute — and a consent runtime swaps
 * them for live ones once that category is granted.
 *
 * Counting pageviews and recording sessions are gated separately on purpose:
 * Umami's tracker is cookie-less and stores nothing on the device, while the
 * recorder captures the DOM.
 */

use Mmoollllee\Filami\Filami;

beforeEach(function () {
    configureUmami(['tracking' => ['environments' => ['*']]]);
});

it('loads the tracker immediately when no category is configured', function () {
    $html = trackingHtml();

    expect($html)->not->toContain('text/plain')
        ->and($html)->not->toContain('data-consent')
        // Ungated: the connection hints are worth having.
        ->and($html)->toContain('rel="preconnect"');
});

it('drops the connection hints behind a consent gate', function () {
    // They would complete DNS/TCP/TLS to the analytics host before any opt-in.
    config()->set('filami.tracking.consent.tracking', 'analytics');

    expect(trackingHtml())
        ->not->toContain('rel="preconnect"')
        ->not->toContain('rel="dns-prefetch"');
});

it('blocks the tracker behind a category', function () {
    config()->set('filami.tracking.consent.tracking', 'analytics');

    $html = trackingHtml();

    expect($html)
        ->toContain('type="text/plain"')
        ->toContain('data-consent="analytics"')
        // The runtime copies every other attribute onto the live tag.
        ->toContain('data-website-id="w-1"')
        ->toContain('src="https://a.example.test/script.js"');
});

it('gates the recorder separately from the tracker', function () {
    // The common setup: pageview counting is cookie-less and runs freely,
    // only the recorder waits for an opt-in.
    config()->set('filami.tracking.consent.recorder', 'analytics');

    $html = trackingHtml(['umami_replay' => true]);

    // Tracker stays live, recorder waits for its own category.
    expect(substr_count($html, 'type="text/plain"'))->toBe(1)
        ->and($html)->toContain('data-consent="analytics"');

    preg_match_all('/<script\b[^>]*>/', $html, $matches);
    $tags = collect($matches[0]);

    expect($tags->first(fn (string $t): bool => str_contains($t, '/script.js')))
        ->not->toContain('text/plain')
        ->and($tags->first(fn (string $t): bool => str_contains($t, '/recorder.js')))
        ->toContain('text/plain');
});

it('lets the recorder inherit the tracking category', function () {
    config()->set('filami.tracking.consent.tracking', 'analytics');

    $html = trackingHtml(['umami_replay' => true]);

    // Both markers AND both inert types — the marker alone does not gate.
    expect(Filami::recorderConsent())->toBe('analytics')
        ->and(substr_count($html, 'data-consent="analytics"'))->toBe(2)
        ->and(substr_count($html, 'type="text/plain"'))->toBe(2);
});

it('ignores a false-y category instead of rendering an empty marker', function () {
    // env('…=false') arrives as boolean false, which filled() calls present —
    // that would skip the fallback and emit data-consent="", gating nothing.
    config()->set('filami.tracking.consent.tracking', 'analytics');
    config()->set('filami.tracking.consent.recorder', false);

    expect(Filami::recorderConsent())->toBe('analytics')
        ->and(trackingHtml(['umami_replay' => true]))
        ->not->toContain('data-consent=""');
});

it('refuses a marker attribute that is not a valid attribute name', function () {
    // A blank name renders ="analytics"; a spaced one smuggles a second
    // attribute that the consent runtime copies onto the live tag.
    config()->set('filami.tracking.consent.tracking', 'analytics');
    config()->set('filami.tracking.consent.attribute', 'data-x onload=alert(1) y');

    expect(trackingHtml())
        ->toContain('data-consent="analytics"')
        ->not->toContain('onload');

    config()->set('filami.tracking.consent.attribute', '');

    expect(trackingHtml())->toContain('data-consent="analytics"');
});

it('drops a category that would break the runtime selector', function () {
    // consent-control concatenates the category into a CSS selector; a quote
    // makes querySelectorAll throw and aborts the whole consent runtime.
    config()->set('filami.tracking.consent.tracking', 'ana"lytics');

    $html = trackingHtml();

    expect($html)->not->toContain('text/plain')
        ->and($html)->not->toContain('lytics');
});

it('treats an unbound recorder="false" as off', function () {
    // ?bool would coerce the string to true and switch recording ON.
    expect(trackingHtml([], '<x-filami::tracking :for="$s" recorder="false" />'))
        ->not->toContain('recorder.js')
        ->and(trackingHtml([], '<x-filami::tracking :for="$s" recorder="0" />'))
        ->not->toContain('recorder.js')
        ->and(trackingHtml([], '<x-filami::tracking :for="$s" recorder />'))
        ->toContain('recorder.js');
});

it('honors a different marker attribute', function () {
    config()->set('filami.tracking.consent.tracking', 'statistics');
    config()->set('filami.tracking.consent.attribute', 'data-cookieconsent');

    expect(trackingHtml())
        ->toContain('data-cookieconsent="statistics"')
        ->not->toContain('data-consent=');
});

it('still renders nothing at all when tracking is switched off', function () {
    config()->set('filami.tracking.consent.tracking', 'analytics');
    config()->set('filami.tracking.environments', ['production']);

    expect(trim(trackingHtml()))->toBe('');
});
