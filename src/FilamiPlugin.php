<?php

namespace Mmoollllee\Filami;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Mmoollllee\Filami\Filament\Pages\UmamiStatistics;

/**
 * Registers the analytics page on a panel — the widgets come with it, so a
 * panel gets a "Statistics" entry and its dashboard stays about the work.
 *
 * Panels that would rather show the widgets on their own dashboard reference
 * the widget classes there instead and do not need this plugin.
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
        $panel->pages([UmamiStatistics::class]);
    }

    public function boot(Panel $panel): void {}
}
