<?php

/**
 * The widgets hide themselves without credentials or a resolvable website id
 * and read everything through the (cached) client. Outside a Filament panel
 * the id falls back to config filami.website_id, which these tests use.
 */

use Illuminate\Support\Facades\Http;
use Mmoollllee\Filami\Filament\Widgets\UmamiStatsOverviewWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiTopPagesWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiVisitorsChartWidget;
use Mmoollllee\Filami\Support\UmamiPeriod;

it('hides all widgets while unconfigured', function () {
    expect(UmamiStatsOverviewWidget::canView())->toBeFalse()
        ->and(UmamiVisitorsChartWidget::canView())->toBeFalse()
        ->and(UmamiTopPagesWidget::canView())->toBeFalse();
});

it('degrades to a placeholder when umami is unreachable', function () {
    configureUmami(['website_id' => 'w-1']);

    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/*' => Http::response(['message' => 'boom'], 500),
    ]);

    $widget = new UmamiStatsOverviewWidget;
    $stats = widgetCall($widget, 'getStats');

    expect($stats)->toHaveCount(1)
        ->and((string) $stats[0]->getValue())->toBe('—');
});

it('shows the widgets once credentials and a website id exist', function () {
    configureUmami(['website_id' => 'w-1']);

    expect(UmamiStatsOverviewWidget::canView())->toBeTrue()
        ->and(UmamiVisitorsChartWidget::canView())->toBeTrue()
        ->and(UmamiTopPagesWidget::canView())->toBeTrue();
});

it('builds the stats overview from the api', function () {
    configureUmami(['website_id' => 'w-1']);

    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/stats*' => Http::sequence()
            ->push(['pageviews' => 100, 'visitors' => 40, 'visits' => 50, 'bounces' => 10, 'totaltime' => 5000])
            ->push(['pageviews' => 50, 'visitors' => 20, 'visits' => 25, 'bounces' => 10, 'totaltime' => 2000]),
        '*/api/websites/w-1/active' => Http::response(['visitors' => 3]),
        '*/api/websites/w-1/pageviews*' => Http::response([
            'pageviews' => [['x' => '2026-07-20T00:00:00Z', 'y' => 10], ['x' => '2026-07-21T00:00:00Z', 'y' => 20]],
            'sessions' => [['x' => '2026-07-20T00:00:00Z', 'y' => 4]],
        ]),
    ]);

    $widget = new UmamiStatsOverviewWidget;
    $stats = widgetCall($widget, 'getStats');

    expect($stats)->toHaveCount(5)
        ->and((string) $stats[0]->getValue())->toBe('3')     // active now
        ->and((string) $stats[1]->getValue())->toBe('40')    // visitors
        ->and((string) $stats[2]->getValue())->toBe('100')   // pageviews
        ->and((string) $stats[3]->getValue())->toBe('1:40 min')
        ->and((string) $stats[4]->getValue())->toBe('20 %');
});

it('builds the chart datasets from the pageview series', function () {
    configureUmami(['website_id' => 'w-1']);

    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/pageviews*' => Http::response([
            'pageviews' => [['x' => '2026-07-20T00:00:00Z', 'y' => 10], ['x' => '2026-07-21T00:00:00Z', 'y' => 20]],
            'sessions' => [['x' => '2026-07-20T00:00:00Z', 'y' => 4], ['x' => '2026-07-21T00:00:00Z', 'y' => 6]],
        ]),
    ]);

    $widget = new UmamiVisitorsChartWidget;
    $data = widgetCall($widget, 'getData');

    expect($data['labels'])->toHaveCount(2)
        ->and($data['datasets'][0]['data'])->toBe([4, 6])
        ->and($data['datasets'][1]['data'])->toBe([10, 20]);
});

it('lists top pages with a link into umami', function () {
    configureUmami(['website_id' => 'w-1']);

    fakeUmamiMetrics([
        ['x' => '/', 'y' => 120],
        ['x' => '/kontakt', 'y' => 30],
    ]);

    $widget = new UmamiTopPagesWidget;
    $pages = widgetCall($widget, 'rows');
    $url = widgetCall($widget, 'umamiDashboardUrl', 'w-1');

    expect($pages)->toHaveCount(2)
        ->and($pages[0])->toBe(['path' => '/', 'views' => 120, 'share' => 100.0])
        // Share is relative to the busiest path, not to the total.
        ->and($pages[1])->toBe(['path' => '/kontakt', 'views' => 30, 'share' => 25.0])
        ->and($url)->toBe('https://a.example.test/websites/w-1');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'type=path'));
});

it('pages through the top pages without asking umami again', function () {
    configureUmami(['website_id' => 'w-1']);

    fakeUmamiMetrics([
        ['x' => '/', 'y' => 120],
        ['x' => '/kontakt', 'y' => 30],
        ['x' => '/mietpark', 'y' => 10],
    ]);

    $widget = new UmamiTopPagesWidget;

    $first = widgetCall($widget, 'paginateRows', 1, 2);
    $second = widgetCall($widget, 'paginateRows', 2, 2);

    expect($first->total())->toBe(3)
        ->and($first->items())->toHaveCount(2)
        ->and($second->items())->toHaveCount(1)
        ->and(array_values($second->items())[0]['path'])->toBe('/mietpark')
        // Record keys must stay unique across pages, or Livewire reuses row state.
        ->and(array_keys($second->items()))->toBe([2]);

    // /metrics takes no offset: one response is fetched and paged in PHP.
    Http::assertSentCount(2); // login + metrics
});

it('asks umami for the shared reporting window', function () {
    configureUmami(['website_id' => 'w-1']);

    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/pageviews*' => Http::response(['pageviews' => [], 'sessions' => []]),
    ]);

    $widget = new UmamiVisitorsChartWidget;
    $widget->umamiPeriod = '24h';

    widgetCall($widget, 'getData');

    // A single day is charted hourly; anything longer would be a flat line.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'pageviews')
        && str_contains($request->url(), 'unit=hour'));
});

it('falls back to the default window for an unknown period', function () {
    // The period is a public Livewire property, so the browser can send
    // anything; it must never reach the API as-is.
    $widget = new UmamiVisitorsChartWidget;
    $widget->umamiPeriod = "'; drop table";

    expect(widgetCall($widget, 'umamiPeriod')->value)->toBe('7d');
});

it('honours a legacy stats_period_days config', function () {
    config()->set('filami.widgets.stats_period_days', 14);

    // Widened to the nearest window that covers 14 days, rather than silently
    // narrowing the dashboard to 7.
    expect(UmamiPeriod::default()->value)->toBe('30d');
});
