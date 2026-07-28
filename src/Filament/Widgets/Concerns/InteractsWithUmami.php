<?php

namespace Mmoollllee\Filami\Filament\Widgets\Concerns;

use Filament\Facades\Filament;
use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\UmamiClient;
use Throwable;

/**
 * Shared plumbing for the Umami widgets: the visibility rule, the reporting
 * window and the client. Kept in one place so a change to "when may this be
 * shown" cannot land on two of the three widgets.
 *
 * Everything is resolved against the current tenant, which may carry its own
 * Umami endpoint — the widgets then read from that instance.
 */
trait InteractsWithUmami
{
    public static function canView(): bool
    {
        return Filami::apiConfigured(static::umamiContext()) && filled(Filami::currentWebsiteId());
    }

    /** The model whose Umami endpoint applies here — the tenant, if any. */
    protected static function umamiContext(): mixed
    {
        try {
            return Filament::getTenant();
        } catch (Throwable) {
            return null;
        }
    }

    protected function umamiWebsiteId(): ?string
    {
        return Filami::currentWebsiteId();
    }

    protected function umami(): UmamiClient
    {
        return Filami::client(static::umamiContext());
    }

    protected function umamiDashboardUrl(?string $websiteId): ?string
    {
        return Filami::websiteDashboardUrl($websiteId, static::umamiContext());
    }

    /** Reporting window in days, shared by the stats and top-pages widgets. */
    protected function periodDays(): int
    {
        return max(1, (int) config('filami.widgets.stats_period_days', 7));
    }
}
