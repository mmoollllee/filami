<?php

namespace Mmoollllee\Filami\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;

/**
 * Most-visited paths within the shared reporting window, with a link into
 * Umami.
 *
 * The ceiling from widgets.top_pages_limit is real: a site with more distinct
 * paths than the limit has a tail the table never shows, which is what the
 * "open in Umami" action is for.
 */
class UmamiTopPagesWidget extends UmamiTableWidget
{
    protected static ?int $sort = 32;

    public function table(Table $table): Table
    {
        // Resolved once: both closures below asked for it, and each call walks
        // the tenant, its attributes and the config.
        $dashboardUrl = $this->umamiDashboardUrl($this->umamiWebsiteId());

        return $this->configureUmamiTable($table)
            ->heading(__('filami::widgets.top_pages'))
            ->headerActions([
                Action::make('openInUmami')
                    ->label(__('filami::widgets.open_in_umami'))
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->link()
                    ->url($dashboardUrl, shouldOpenInNewTab: true)
                    ->visible(filled($dashboardUrl)),
            ])
            ->columns([
                TextColumn::make('path')
                    ->label(__('filami::widgets.page_path'))
                    ->weight('medium')
                    // Long paths would otherwise widen the column past the
                    // widget and push the count out of sight.
                    ->limit(48)
                    ->tooltip(fn (array $record): string => $record['path']),
                ViewColumn::make('share')
                    ->label('')
                    ->view('filami::widgets.share-bar')
                    ->grow(false),
                TextColumn::make('views')
                    ->label(__('filami::widgets.pageviews'))
                    ->numeric()
                    ->alignEnd(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedChartBar);
    }

    /** @return list<array{path: string, views: int, share: float}> */
    protected function rows(): array
    {
        $metrics = $this->fetchRows(fn (string $websiteId): array => $this->umami()->metrics(
            $websiteId,
            // v3 calls the pathname metric "path" (v2 called it "url").
            'path',
            $this->umamiPeriod()->start(),
            now(),
            max(1, (int) config('filami.widgets.top_pages_limit', 100)),
        ));

        return $this->withShare($metrics, 'path', 'views', blankLabel: '/');
    }
}
