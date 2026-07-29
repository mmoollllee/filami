<?php

namespace Mmoollllee\Filami\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;

/** Minimal panel so the widgets can be rendered the way a dashboard renders them. */
class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('test')
            ->path('test');
    }
}
