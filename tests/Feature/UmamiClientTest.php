<?php

/**
 * The client hides Umami's auth quirks: self-hosted instances only issue
 * login tokens (no API keys), tokens never expire server-side, and the
 * /stats response shape changed between v2 and v3.
 */

use Illuminate\Support\Facades\Http;
use Mmoollllee\Filami\Support\UmamiStats;
use Mmoollllee\Filami\UmamiClient;
use Throwable;

it('logs in once and reuses the cached token', function () {
    configureUmami();

    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 'tok-1']),
        '*/api/websites' => Http::response(['id' => 'w-1', 'name' => 'Site', 'domain' => 'site.test']),
    ]);

    $client = app(UmamiClient::class);
    $client->createWebsite('Site', 'site.test');
    $client->createWebsite('Other', 'other.test');

    Http::assertSentCount(3); // one login, two creates

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/websites')
        && $request->hasHeader('Authorization', 'Bearer tok-1')
        && $request['name'] === 'Site'
        && $request['domain'] === 'site.test');
});

it('refreshes the token and retries once on 401', function () {
    configureUmami();

    Http::fake([
        '*/api/auth/login' => Http::sequence()
            ->push(['token' => 'stale'])
            ->push(['token' => 'fresh']),
        '*/api/websites/w-1/stats*' => Http::sequence()
            ->push(['message' => 'unauthorized'], 401)
            ->push(['pageviews' => 5, 'visitors' => 2, 'visits' => 3, 'bounces' => 1, 'totaltime' => 30]),
    ]);

    $stats = app(UmamiClient::class)->stats('w-1', now()->subDay(), now());

    expect($stats->pageviews)->toBe(5);
    Http::assertSentCount(4); // login, 401, re-login, 200
});

it('normalizes the v2 stats shape', function () {
    $stats = UmamiStats::fromResponse([
        'pageviews' => ['value' => 10, 'prev' => 4],
        'visitors' => ['value' => 3, 'prev' => 1],
        'visits' => ['value' => 4, 'prev' => 2],
        'bounces' => ['value' => 2, 'prev' => 2],
        'totaltime' => ['value' => 100, 'prev' => 50],
    ]);

    expect($stats->pageviews)->toBe(10)
        ->and($stats->visitors)->toBe(3)
        ->and($stats->bounceRate())->toBe(50)
        ->and($stats->averageVisitSeconds())->toBe(25);
});

it('normalizes the v3 stats shape', function () {
    $stats = UmamiStats::fromResponse([
        'pageviews' => 15171,
        'visitors' => 4415,
        'visits' => 5680,
        'bounces' => 3567,
        'totaltime' => 809968,
        'comparison' => ['pageviews' => 38675, 'visitors' => 10568],
    ]);

    expect($stats->pageviews)->toBe(15171)
        ->and($stats->visitors)->toBe(4415)
        ->and($stats->bounceRate())->toBe(63)
        ->and($stats->averageVisitSeconds())->toBe(142);
});

it('parses both active-visitor response shapes', function () {
    configureUmami();

    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-a/active' => Http::response(['visitors' => 4]),
        '*/api/websites/w-b/active' => Http::response([['x' => 7]]),
    ]);

    $client = app(UmamiClient::class);

    expect($client->activeVisitors('w-a'))->toBe(4)
        ->and($client->activeVisitors('w-b'))->toBe(7);
});

it('serves a second identical window from the cache', function () {
    // The widgets pass a fresh now() on every render; keys are snapped to the
    // cache window so the 300 s TTL actually buys something.
    configureUmami();

    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/stats*' => Http::response(['pageviews' => 1, 'visitors' => 1, 'visits' => 1, 'bounces' => 0, 'totaltime' => 10]),
    ]);

    $client = app(UmamiClient::class);
    $client->stats('w-1', now()->subDays(7), now());
    $client->stats('w-1', now()->subDays(7), now());

    Http::assertSentCount(2); // one login, one /stats
});

it('stops dialling an unreachable instance for a moment', function () {
    configureUmami();

    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/stats*' => Http::response(['message' => 'boom'], 500),
    ]);

    $client = app(UmamiClient::class);

    foreach (range(1, 3) as $ignored) {
        try {
            $client->stats('w-1', now()->subDays(7), now());
        } catch (Throwable) {
            // expected — the widgets turn this into a placeholder
        }
    }

    Http::assertSentCount(2); // login + one failed call; the rest are negative-cached
});

it('treats deleting a missing website as success', function () {
    configureUmami();

    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/gone' => Http::response(['message' => 'not found'], 404),
    ]);

    app(UmamiClient::class)->deleteWebsite('gone');

    Http::assertSentCount(2);
});
