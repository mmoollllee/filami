<?php

/**
 * The widgets rendered the way a dashboard renders them, inside a real panel.
 * Their data is unit-tested in WidgetsTest; what is checked here is the wiring
 * that only exists once Livewire and the Filament schema/table layers run —
 * the window select, the paginated table, and the broadcast that keeps the
 * three in step.
 */

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mmoollllee\Filami\Filament\Widgets\UmamiStatsOverviewWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiTopPagesWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiVisitorsChartWidget;

beforeEach(function () {
    configureUmami(['website_id' => 'w-1']);
});

it('renders the window select in the stats header', function () {
    fakeUmamiStats(['pageviews' => 10, 'visitors' => 5, 'visits' => 5, 'bounces' => 1, 'totaltime' => 100], active: 1);

    Livewire::test(UmamiStatsOverviewWidget::class)
        ->assertOk()
        ->assertSeeHtml('wire:model.live="umamiPeriod"')
        ->assertSee(__('filami::widgets.filters.7d'))
        ->assertSee(__('filami::widgets.filters.90d'));
});

it('broadcasts a window change and remembers it', function () {
    fakeUmamiStats(['pageviews' => 10, 'visitors' => 5, 'visits' => 5, 'bounces' => 1, 'totaltime' => 100], active: 1);

    Livewire::test(UmamiStatsOverviewWidget::class)
        ->set('umamiPeriod', '30d')
        ->assertDispatched('filami-period-updated', period: '30d');

    // Remembered per panel, so a reload — or a widget mounting later — starts
    // out on the window the reader picked.
    expect(session('filami.period.test'))->toBe('30d');
});

it('follows a window change broadcast by the stats widget', function () {
    fakeUmamiPageviews();

    Livewire::test(UmamiVisitorsChartWidget::class)
        ->assertSet('umamiPeriod', '7d')
        ->dispatch('filami-period-updated', period: '24h')
        ->assertSet('umamiPeriod', '24h')
        // The listener must not re-broadcast, or the widgets ping-pong forever.
        ->assertNotDispatched('filami-period-updated');
});

it('renders the top pages as a paginated table', function () {
    fakeUmamiMetrics(fakeUmamiPagePaths(12));

    Livewire::test(UmamiTopPagesWidget::class)
        ->assertOk()
        ->assertSee('/page-01')
        ->assertSee('/page-05')
        // Page size is 5, so the sixth row is behind the pagination.
        ->assertDontSee('/page-06')
        ->call('setPage', 2)
        ->assertSee('/page-06')
        ->assertDontSee('/page-01');
});

it('returns to the first page when the window changes', function () {
    fakeUmamiMetrics(fakeUmamiPagePaths(12));

    Livewire::test(UmamiTopPagesWidget::class)
        ->call('setPage', 2)
        ->assertSee('/page-06')
        ->dispatch('filami-period-updated', period: '30d')
        // Page 2 of the old window is not page 2 of the new one.
        ->assertSee('/page-01');
});

it('says why the table is empty when umami is down', function () {
    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/metrics*' => Http::response(['message' => 'boom'], 500),
    ]);

    Livewire::test(UmamiTopPagesWidget::class)
        ->assertOk()
        ->assertSee(__('filami::widgets.unreachable'));
});
