<?php

namespace Mmoollllee\Filami;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Mmoollllee\Filami\Filament\Widgets\UmamiEventsWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiStatsOverviewWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiTopPagesWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiVisitorsChartWidget;

/**
 * Registers the Umami widgets on a panel. Panels whose dashboard lists its
 * widgets explicitly (like filament-cms) reference the widget classes there
 * instead and do not need this plugin.
 */
class FilamiPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filami';
    }

    public function register(Panel $panel): void
    {
        $panel->widgets([
            UmamiStatsOverviewWidget::class,
            UmamiVisitorsChartWidget::class,
            UmamiTopPagesWidget::class,
            UmamiEventsWidget::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
