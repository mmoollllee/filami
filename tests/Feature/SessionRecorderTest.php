<?php

/**
 * Session replay and the heatmaps built on it need Umami's separate recorder
 * script next to the tracker. Umami enables the feature per website, so the
 * decision follows the model rather than a global switch.
 */

use Illuminate\Support\Facades\Blade;
use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\Tests\Fixtures\Site;
use Mmoollllee\Filami\Tests\Fixtures\TrackedSite;

beforeEach(function () {
    configureUmami(['tracking' => ['environments' => ['*']]]);
});

it('ships only the tracker by default', function () {
    $site = Site::create(['name' => 'Acme', 'umami_website_id' => 'w-1']);

    $html = Blade::render('<x-filami::tracking :for="$site" />', ['site' => $site]);

    expect($html)->toContain('/script.js')
        ->and($html)->not->toContain('recorder.js');
});

it('adds the recorder for a model that has replay enabled', function () {
    $site = Site::create(['name' => 'Acme', 'umami_website_id' => 'w-1', 'umami_replay' => true]);

    $html = Blade::render('<x-filami::tracking :for="$site" />', ['site' => $site]);

    expect($html)
        ->toContain('src="https://a.example.test/script.js"')
        ->toContain('src="https://a.example.test/recorder.js"');

    // Both scripts must carry the same website id, or the replay is orphaned.
    expect(substr_count($html, 'data-website-id="w-1"'))->toBe(2);
});

it('follows the model own endpoint for the recorder too', function () {
    $site = Site::create([
        'name' => 'Acme',
        'umami_website_id' => 'w-1',
        'umami_url' => 'https://own.test',
        'umami_replay' => true,
    ]);

    expect(Blade::render('<x-filami::tracking :for="$site" />', ['site' => $site]))
        ->toContain('src="https://own.test/recorder.js"');
});

it('resolves replay through the contract', function () {
    $site = TrackedSite::create([
        'title' => 'Legacy',
        'host' => 'legacy.test',
        'analytics_id' => 'w-legacy',
        'records' => true,
    ]);

    expect(Filami::recordsSessions($site))->toBeTrue()
        ->and(Blade::render('<x-filami::tracking :for="$s" />', ['s' => $site]))
        ->toContain('recorder.js');
});

it('lets the tag override what the model says', function () {
    $off = Site::create(['name' => 'Acme', 'umami_website_id' => 'w-1']);
    $on = Site::create(['name' => 'Acme', 'umami_website_id' => 'w-2', 'umami_replay' => true]);

    expect(Blade::render('<x-filami::tracking :for="$s" recorder />', ['s' => $off]))
        ->toContain('recorder.js')
        ->and(Blade::render('<x-filami::tracking :for="$s" :recorder="false" />', ['s' => $on]))
        ->not->toContain('recorder.js');
});

it('uses the configured default without a model', function () {
    configureUmami(['website_id' => 'single', 'tracking' => ['environments' => ['*'], 'recorder' => true]]);

    expect(Blade::render('<x-filami::tracking />'))->toContain('recorder.js');
});

it('does not repeat tracker-only attributes on the recorder', function () {
    // data-domains limits the tracker but not the recorder, so repeating it
    // would suggest a restriction that does not hold for recordings.
    $site = Site::create(['name' => 'Acme', 'umami_website_id' => 'w-1', 'umami_replay' => true]);

    $html = Blade::render('<x-filami::tracking :for="$s" data-domains="acme.test" fetchpriority="low" />', ['s' => $site]);

    [, $recorderTag] = explode('recorder.js', $html, 2);

    expect(substr_count($html, 'data-domains'))->toBe(1)
        ->and($recorderTag)->not->toContain('fetchpriority');
});

it('passes data-host-url on to the recorder', function () {
    // One of exactly two attributes the recorder reads.
    $site = Site::create(['name' => 'Acme', 'umami_website_id' => 'w-1', 'umami_replay' => true]);

    $html = Blade::render('<x-filami::tracking :for="$s" data-host-url="https://collect.test" />', ['s' => $site]);

    expect(substr_count($html, 'data-host-url="https://collect.test"'))->toBe(2);
});

it('honors a renamed recorder script', function () {
    configureUmami(['tracking' => ['environments' => ['*'], 'recorder_script' => 'rec.js']]);

    $site = Site::create(['name' => 'Acme', 'umami_website_id' => 'w-1', 'umami_replay' => true]);

    expect(Blade::render('<x-filami::tracking :for="$site" />', ['site' => $site]))
        ->toContain('src="https://a.example.test/rec.js"');
});

it('never ships the recorder when tracking itself is off', function () {
    configureUmami(['tracking' => ['environments' => ['production'], 'recorder' => true]]);

    $site = Site::create(['name' => 'Acme', 'umami_website_id' => 'w-1', 'umami_replay' => true]);

    expect(trim(Blade::render('<x-filami::tracking :for="$site" />', ['site' => $site])))->toBe('');
});
