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

/** Calls a protected widget method — the widgets expose their data internally only. */
function widgetCall(object $widget, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($widget, $method))->invoke($widget, ...$arguments);
}

/**
 * Fakes the login plus the read endpoints the widgets use. One helper rather
 * than one per test file: when a URL shape changes, a second copy would keep
 * faking a route the client no longer calls and its tests would pass anyway.
 *
 * @param  list<array{x: string, y: int}>  $metrics  /metrics — top pages AND events
 * @param  list<array<string, mixed>>  $properties  /event-data/properties
 * @param  list<array<string, mixed>>  $values  /event-data/values
 */
function fakeUmamiMetrics(array $metrics, array $properties = [], array $values = []): void
{
    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/metrics*' => Http::response($metrics),
        '*/api/websites/w-1/event-data/properties*' => Http::response($properties),
        '*/api/websites/w-1/event-data/values*' => Http::response($values),
    ]);
}

/** The stats overview's three reads. */
function fakeUmamiStats(array $stats = ['pageviews' => 1, 'visitors' => 1, 'visits' => 1, 'bounces' => 0, 'totaltime' => 10], int $active = 0): void
{
    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/stats*' => Http::response($stats),
        '*/api/websites/w-1/active' => Http::response(['visitors' => $active]),
    ]);
}

/** The chart's one read. */
function fakeUmamiPageviews(array $pageviews = [], array $sessions = []): void
{
    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/pageviews*' => Http::response(['pageviews' => $pageviews, 'sessions' => $sessions]),
    ]);
}

/** Zero-padded so "/page-01" is not a substring of "/page-10". */
function fakeUmamiPagePaths(int $count): array
{
    return collect(range(1, $count))
        ->map(fn (int $i): array => ['x' => '/page-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'y' => 100 - $i])
        ->all();
}

/**
 * Renders the tracking component for a freshly created Site fixture. Lives
 * here rather than in a test file so a second file using it cannot redeclare
 * it — Pest loads every test into one process.
 */
function trackingHtml(array $attributes = [], string $tag = '<x-filami::tracking :for="$s" />'): string
{
    $site = \Mmoollllee\Filami\Tests\Fixtures\Site::create(array_replace([
        'name' => 'Acme',
        'umami_website_id' => 'w-1',
    ], $attributes));

    return \Illuminate\Support\Facades\Blade::render($tag, ['s' => $site]);
}
