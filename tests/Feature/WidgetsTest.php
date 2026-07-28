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
    $stats = (new ReflectionMethod($widget, 'getStats'))->invoke($widget);

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
    $stats = (new ReflectionMethod($widget, 'getStats'))->invoke($widget);

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
    $data = (new ReflectionMethod($widget, 'getData'))->invoke($widget);

    expect($data['labels'])->toHaveCount(2)
        ->and($data['datasets'][0]['data'])->toBe([4, 6])
        ->and($data['datasets'][1]['data'])->toBe([10, 20]);
});

it('lists top pages with a link into umami', function () {
    configureUmami(['website_id' => 'w-1']);

    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/metrics*' => Http::response([
            ['x' => '/', 'y' => 120],
            ['x' => '/kontakt', 'y' => 30],
        ]),
    ]);

    $widget = new UmamiTopPagesWidget;
    $data = (new ReflectionMethod($widget, 'getViewData'))->invoke($widget);

    expect($data['pages'])->toHaveCount(2)
        ->and($data['max'])->toBe(120)
        ->and($data['umamiUrl'])->toBe('https://a.example.test/websites/w-1');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'type=path'));
});
