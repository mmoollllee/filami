<?php

/**
 * Regressions from the code review of the shared reporting window.
 *
 * All three bugs shared one shape: the window a widget *reports* and the window
 * it *queried* drifted apart, which is precisely the misreading the shared
 * select was introduced to prevent — and the kind that produces a plausible,
 * wrong dashboard rather than an error.
 */

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mmoollllee\Filami\Filament\Widgets\UmamiStatsOverviewWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiTopPagesWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiVisitorsChartWidget;

beforeEach(function () {
    configureUmami(['website_id' => 'w-1']);
});

/** Hours spanned by the first request whose URL contains $needle. */
function queriedSpanHours(string $needle): int
{
    $span = null;

    Http::assertSent(function ($request) use ($needle, &$span): bool {
        if ($span === null && str_contains($request->url(), $needle)) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $span = (int) round((((int) $query['endAt'] - (int) $query['startAt']) / 1000) / 3600);
        }

        return true;
    });

    return (int) $span;
}

it('queries the remembered window, not just labels it', function () {
    // The window was restored in booted(), which Livewire runs AFTER mount() —
    // ChartWidget::mount() had already fetched and memoised the default window,
    // so the chart showed 7 days under a "last 24 hours" caption.
    session()->put('filami.period.test', '24h');

    fakeUmamiPageviews();

    Livewire::test(UmamiVisitorsChartWidget::class)->assertSet('umamiPeriod', '24h');

    expect(queriedSpanHours('pageviews'))->toBe(24);
});

it('queries the remembered window in the stats overview too', function () {
    session()->put('filami.period.test', '30d');

    fakeUmamiStats();

    Livewire::test(UmamiStatsOverviewWidget::class)->assertSet('umamiPeriod', '30d');

    expect(queriedSpanHours('stats'))->toBe(24 * 30);
});

it('keeps the table caption on the same window as its rows', function () {
    // The description was a plain string evaluated while Filament built and
    // cached the Table, so it froze at the pre-change window while records()
    // (a Closure) already fetched the new one.
    session()->put('filami.period.test', '30d');
    fakeUmamiMetrics([['x' => '/', 'y' => 10]]);

    $component = Livewire::test(UmamiTopPagesWidget::class);

    expect($component->html())->toContain(__('filami::widgets.filters.30d'))
        ->not->toContain(__('filami::widgets.filters.7d'));

    $component->dispatch('filami-period-updated', period: '24h');

    expect($component->html())->toContain(__('filami::widgets.filters.24h'))
        ->not->toContain(__('filami::widgets.filters.30d'));
});

it('leaves default_period unset so the legacy key stays reachable', function () {
    // The widening rule itself is pinned in WidgetsTest. What matters here is
    // the precondition it needs: the shipped config must NOT carry a default,
    // or the recursive merge would always win and the legacy branch would be
    // dead code again — which is exactly how the bug got in.
    expect(config('filami.widgets.default_period'))->toBeNull();
});

it('ignores a period broadcast without a payload', function () {
    // Livewire event payloads are client-controlled; a bare dispatch used to be
    // an ArgumentCountError rather than a no-op.
    Http::fake(['*' => Http::response(['pageviews' => [], 'sessions' => []])]);

    Livewire::test(UmamiVisitorsChartWidget::class)
        ->dispatch('filami-period-updated')
        ->assertOk()
        ->assertSet('umamiPeriod', '7d');
});
