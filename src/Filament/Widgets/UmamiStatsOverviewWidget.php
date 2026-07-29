<?php

namespace Mmoollllee\Filami\Filament\Widgets;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Mmoollllee\Filami\Filament\Widgets\Concerns\InteractsWithUmami;
use Mmoollllee\Filami\Support\UmamiPeriod;
use Mmoollllee\Filami\Support\UmamiStats;
use Throwable;

/**
 * Key metrics for the current tenant's website: live visitors plus the
 * reporting window (visitors, pageviews, visit time, bounce rate) compared
 * against the previous window. Hides itself without credentials or website.
 *
 * This widget owns the reporting-window select for the whole analytics section
 * — one control rather than four, with the chart and the two tables following
 * along ({@see InteractsWithUmami}). It therefore has to be on the page for
 * the window to be changeable; the others read the shared state but render no
 * control of their own. {@see \Mmoollllee\Filami\Filament\Pages\UmamiStatistics}
 * puts all four together.
 */
class UmamiStatsOverviewWidget extends StatsOverviewWidget
{
    use InteractsWithUmami;

    protected static ?int $sort = 30;

    protected int|string|array $columnSpan = 'full';

    /** Matches cache.active_ttl, the shortest-lived value on display. */
    protected ?string $pollingInterval = '60s';

    /**
     * All five stats on one row. The inherited heuristic would put four up top
     * and leave the fifth alone on a second row, which costs the dashboard a
     * whole band of vertical space for one number.
     */
    protected function getColumns(): int|array|null
    {
        return ['@md' => 3, '@xl' => 5, '!@lg' => 5];
    }

    /**
     * None: the page this sits on is titled "Statistics", and a card heading
     * repeating it (or naming the vendor, as it used to) tells a reader
     * nothing. The window select still renders — Filament shows the section
     * header for it either way.
     */
    public function getHeading(): ?string
    {
        return null;
    }

    /** Hangs the window select off the section header, next to the heading. */
    public function getSectionContentComponent(): Component
    {
        return parent::getSectionContentComponent()
            ->afterHeader(
                View::make('filami::widgets.period-filter')
                    ->viewData(['periodOptions' => UmamiPeriod::options()]),
            );
    }

    protected function getStats(): array
    {
        $websiteId = $this->umamiWebsiteId();

        if (blank($websiteId)) {
            return [];
        }

        $client = $this->umami();
        $period = $this->umamiPeriod();
        $end = now();
        $start = $period->start($end);
        $previousStart = $period->previousStart($end);

        try {
            $current = $client->stats($websiteId, $start, $end);
            $previous = $client->stats($websiteId, $previousStart, $start);
            $active = $client->activeVisitors($websiteId);
        } catch (Throwable $exception) {
            report($exception);

            return [
                Stat::make(__('filami::widgets.pageviews'), '—')
                    ->description(__('filami::widgets.unreachable'))
                    ->color('gray'),
            ];
        }

        // No sparkline here on purpose: the chart widget next to this one
        // renders the same series in full, for the price of another API call.
        return [
            Stat::make(__('filami::widgets.active_now'), $this->formatNumber($active))
                ->description(__('filami::widgets.active_now_description'))
                ->descriptionIcon('heroicon-m-signal')
                ->color('success'),
            $this->trendStat(__('filami::widgets.visitors'), $current->visitors, $previous->visitors),
            $this->trendStat(__('filami::widgets.pageviews'), $current->pageviews, $previous->pageviews),
            Stat::make(__('filami::widgets.avg_visit_time'), $this->formatDuration($current->averageVisitSeconds()))
                ->color('gray'),
            $this->bounceStat($current, $previous),
        ];
    }

    protected function trendStat(string $label, int $value, int $previous): Stat
    {
        $stat = Stat::make($label, $this->formatNumber($value));

        if ($previous < 1) {
            return $stat->color('gray');
        }

        $change = (int) round((($value - $previous) / $previous) * 100);

        if ($change === 0) {
            return $stat->description(__('filami::widgets.unchanged'))
                ->descriptionIcon('heroicon-m-minus')
                ->color('gray');
        }

        return $stat
            ->description(__('filami::widgets.vs_previous', ['change' => ($change > 0 ? '+' : '').$change.' %']))
            ->descriptionIcon($change > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($change > 0 ? 'success' : 'danger');
    }

    protected function bounceStat(UmamiStats $current, UmamiStats $previous): Stat
    {
        $rate = $current->bounceRate();
        $stat = Stat::make(__('filami::widgets.bounce_rate'), $rate === null ? '—' : $rate.' %');
        $previousRate = $previous->bounceRate();

        if ($rate === null || $previousRate === null) {
            return $stat->color('gray');
        }

        $diff = $rate - $previousRate;

        if ($diff === 0) {
            return $stat->description(__('filami::widgets.unchanged'))
                ->descriptionIcon('heroicon-m-minus')
                ->color('gray');
        }

        // Percentage points; a falling bounce rate is the good direction.
        return $stat
            ->description(__('filami::widgets.vs_previous', ['change' => ($diff > 0 ? '+' : '').$diff.' pp']))
            ->descriptionIcon($diff > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($diff < 0 ? 'success' : 'danger');
    }

    protected function formatNumber(int $value): string
    {
        return Number::format($value, locale: app()->getLocale()) ?: (string) $value;
    }

    protected function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return sprintf('%d:%02d min', intdiv($seconds, 60), $seconds % 60);
    }
}
