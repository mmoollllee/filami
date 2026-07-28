<?php

namespace Mmoollllee\Filami\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Mmoollllee\Filami\Filament\Widgets\Concerns\InteractsWithUmami;
use Throwable;

/** Sessions and pageviews over time for the current tenant's website. */
class UmamiVisitorsChartWidget extends ChartWidget
{
    use InteractsWithUmami;

    protected static ?int $sort = 31;

    protected int|string|array $columnSpan = 1;

    /**
     * Filament polls widgets every 5s by default. The underlying responses are
     * cached for minutes, so that would be ~700 Livewire round-trips an hour
     * per open tab re-rendering byte-identical output.
     */
    protected ?string $pollingInterval = '60s';

    public ?string $filter = '30d';

    public function getHeading(): ?string
    {
        return __('filami::widgets.chart_heading');
    }

    protected function getFilters(): ?array
    {
        return [
            '24h' => __('filami::widgets.filters.24h'),
            '7d' => __('filami::widgets.filters.7d'),
            '30d' => __('filami::widgets.filters.30d'),
            '90d' => __('filami::widgets.filters.90d'),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $websiteId = $this->umamiWebsiteId();

        if (blank($websiteId)) {
            return ['datasets' => [], 'labels' => []];
        }

        [$start, $unit] = match ($this->filter) {
            '24h' => [now()->subDay(), 'hour'],
            '7d' => [now()->subDays(7), 'day'],
            '90d' => [now()->subDays(90), 'day'],
            default => [now()->subDays(30), 'day'],
        };

        try {
            $series = $this->umami()->pageviewSeries($websiteId, $start, now(), $unit);
        } catch (Throwable $exception) {
            report($exception);

            return ['datasets' => [], 'labels' => []];
        }

        $sessionSeries = collect($series['sessions'])->keyBy('x');
        $labels = [];
        $pageviews = [];
        $sessions = [];

        foreach ($series['pageviews'] as $point) {
            $x = (string) ($point['x'] ?? '');
            $labels[] = $this->formatLabel($x, $unit);
            $pageviews[] = (int) ($point['y'] ?? 0);
            $sessions[] = (int) ($sessionSeries[$x]['y'] ?? 0);
        }

        return [
            'datasets' => [
                [
                    // Sessions, not unique visitors: /pageviews returns visits.
                    // Labelling this "Besucher" would contradict the stats
                    // widget, which shows /stats visitors for the same window.
                    'label' => __('filami::widgets.sessions'),
                    'data' => $sessions,
                    'borderColor' => '#0ea5e9',
                    'backgroundColor' => 'rgba(14, 165, 233, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                ],
                [
                    'label' => __('filami::widgets.pageviews'),
                    'data' => $pageviews,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function formatLabel(string $timestamp, string $unit): string
    {
        try {
            $date = Carbon::parse($timestamp)->timezone(config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return $timestamp;
        }

        return $unit === 'hour' ? $date->format('H:i') : $date->translatedFormat('d. M');
    }
}
