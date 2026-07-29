<?php

/**
 * The analytics widgets live on a page of their own, so the panel's dashboard
 * stays about the work.
 *
 * The page hides itself under exactly the conditions its widgets do — a menu
 * entry that opens an empty screen is worse than no entry, and this is the
 * kind of thing that only breaks once someone removes credentials.
 */

use Livewire\Livewire;
use Mmoollllee\Filami\Filament\Pages\UmamiStatistics;
use Mmoollllee\Filami\Filament\Widgets\UmamiEventsWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiStatsOverviewWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiTopPagesWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiVisitorsChartWidget;
use Mmoollllee\Filami\FilamiPlugin;

it('stays out of the navigation while unconfigured', function () {
    expect(UmamiStatistics::canAccess())->toBeFalse();
});

it('appears once credentials and a website id exist', function () {
    configureUmami(['website_id' => 'w-1']);

    expect(UmamiStatistics::canAccess())->toBeTrue();
});

it('gathers all four widgets', function () {
    expect((new UmamiStatistics)->getWidgets())->toBe([
        UmamiStatsOverviewWidget::class,
        UmamiVisitorsChartWidget::class,
        UmamiTopPagesWidget::class,
        UmamiEventsWidget::class,
    ]);
});

it('is titled after the subject, not the vendor', function () {
    // "Umami" named the tool where a reader expects the topic.
    expect(UmamiStatistics::getNavigationLabel())->toBe('Statistics')
        ->and((new UmamiStatistics)->getTitle())->toBe('Statistics')
        ->and(UmamiStatistics::getNavigationLabel())->not->toContain('Umami');
});

it('does not take the dashboard route', function () {
    // Filament's Dashboard uses '/', which this would otherwise collide with.
    expect(UmamiStatistics::getRoutePath(filament()->getDefaultPanel()))->toBe('statistics');
});

it('renders and mounts its widgets', function () {
    configureUmami(['website_id' => 'w-1']);
    fakeUmamiStats();

    // The widgets are Livewire components of their own, so the page holds
    // their placeholders rather than their markup — what matters here is that
    // all four are mounted. Their contents are covered by WidgetRenderingTest.
    Livewire::test(UmamiStatistics::class)
        ->assertOk()
        ->assertSee('Statistics')
        ->assertSeeLivewire(UmamiStatsOverviewWidget::class)
        ->assertSeeLivewire(UmamiVisitorsChartWidget::class)
        ->assertSeeLivewire(UmamiTopPagesWidget::class)
        ->assertSeeLivewire(UmamiEventsWidget::class);
});

it('explains itself when tracking is configured but inactive', function () {
    configureUmami(['website_id' => 'w-1']); // default environments: production only

    expect((new UmamiStatistics)->getSubheading())
        ->toContain('production');
});

it('is what the plugin registers', function () {
    $panel = filament()->getDefaultPanel();

    FilamiPlugin::make()->register($panel);

    expect($panel->getPages())->toContain(UmamiStatistics::class);
});
