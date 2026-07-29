<?php

namespace Mmoollllee\Filami\Filament\Pages;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Filami\Filament\Widgets\UmamiEventsWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiStatsOverviewWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiTopPagesWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiVisitorsChartWidget;
use Mmoollllee\Filami\Filami;
use Throwable;

/**
 * A page of its own for the analytics widgets, so the main dashboard stays
 * about the work and the numbers get the room to be read.
 *
 * Extends Filament's Dashboard for its widget grid — with a route path of its
 * own, so it sits beside the real dashboard rather than replacing it.
 *
 * Register it on a panel:
 *
 *     ->pages([Dashboard::class, UmamiStatistics::class])
 *
 * or let {@see \Mmoollllee\Filami\FilamiPlugin} do it. The page hides itself
 * under exactly the conditions the widgets do, so a panel without credentials
 * or a website id shows no empty menu entry.
 */
class UmamiStatistics extends Dashboard
{
    /**
     * Overridden because Dashboard's is '/', which would collide with the
     * panel's real dashboard. Change it per app by subclassing.
     */
    protected static string $routePath = 'statistics';

    /** Right after the dashboard (-2), before everything else. */
    protected static ?int $navigationSort = -1;

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedChartBar;
    }

    public static function getNavigationLabel(): string
    {
        return __('filami::pages.statistics');
    }

    public function getTitle(): string
    {
        return __('filami::pages.statistics');
    }

    /**
     * Same gate as the widgets: without credentials or a resolvable website id
     * they all hide themselves, and a page whose every widget is invisible is
     * a menu entry leading to an empty screen.
     */
    public static function canAccess(): bool
    {
        return UmamiStatsOverviewWidget::canView();
    }

    public function getWidgets(): array
    {
        return [
            UmamiStatsOverviewWidget::class,
            UmamiVisitorsChartWidget::class,
            UmamiTopPagesWidget::class,
            UmamiEventsWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    /**
     * Why nothing is being recorded, when that is the case — the gate is
     * otherwise silent, which makes a correctly configured site look broken.
     *
     * Asked with the tenant, not globally: a tenant can name its own Umami
     * server and website id, so the global config alone would give the wrong
     * answer on a per-tenant setup.
     */
    public function getSubheading(): ?string
    {
        try {
            $tenant = Filament::getTenant();
        } catch (Throwable) {
            $tenant = null;
        }

        return Filami::inactiveReason($tenant);
    }
}
