<?php

namespace Mmoollllee\Filami\Filament\Widgets;

use Filament\Widgets\Widget;
use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\Filament\Widgets\Concerns\InteractsWithUmami;
use Throwable;

/** Most-visited paths within the reporting window, with a link into Umami. */
class UmamiTopPagesWidget extends Widget
{
    use InteractsWithUmami;

    protected string $view = 'filami::widgets.top-pages';

    protected static ?int $sort = 32;

    protected int|string|array $columnSpan = 1;

    protected function getViewData(): array
    {
        $websiteId = $this->umamiWebsiteId();
        $days = $this->periodDays();

        $pages = null;

        if (filled($websiteId)) {
            try {
                // v3 calls the pathname metric "path" (v2 called it "url").
                $pages = $this->umami()->metrics($websiteId, 'path', now()->subDays($days), now(), 8);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return [
            'pages' => $pages,
            'max' => max(1, (int) collect($pages ?? [])->max('y')),
            'days' => $days,
            'umamiUrl' => Filami::websiteDashboardUrl($websiteId),
        ];
    }
}
