<?php

namespace Mmoollllee\Filami\Filament\Widgets\Concerns;

use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\UmamiClient;

/**
 * Shared plumbing for the Umami widgets: the visibility rule, the reporting
 * window and the client. Kept in one place so a change to "when may this be
 * shown" cannot land on two of the three widgets.
 */
trait InteractsWithUmami
{
    public static function canView(): bool
    {
        return Filami::apiConfigured() && filled(Filami::currentWebsiteId());
    }

    protected function umamiWebsiteId(): ?string
    {
        return Filami::currentWebsiteId();
    }

    protected function umami(): UmamiClient
    {
        return app(UmamiClient::class);
    }

    /** Reporting window in days, shared by the stats and top-pages widgets. */
    protected function periodDays(): int
    {
        return max(1, (int) config('filami.widgets.stats_period_days', 7));
    }
}
