<?php

namespace Mmoollllee\Filami\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Mmoollllee\Filami\Filament\Widgets\Concerns\InteractsWithUmami;
use Throwable;

/**
 * Sessions and pageviews over time for the current tenant's website.
 *
 * The window comes from the shared state the stats widget controls, not from a
 * filter of its own — two selects a few pixels apart, each relabelling half the
 * dashboard, is how a reader ends up comparing two different weeks by accident.
 */
class UmamiVisitorsChartWidget extends ChartWidget
{
    use InteractsWithUmami;

    protected static ?int $sort = 31;

    protected int|string|array $columnSpan = 1;

    /**
     * Capped so the chart cannot outgrow the top-pages table beside it: without
     * it Chart.js takes whatever height the grid row offers, and the pair stops
     * lining up as soon as the table holds fewer rows than its page size.
     */
    protected ?string $maxHeight = '14rem';

    /**
     * Filament polls widgets every 5s by default. The underlying responses are
     * cached for minutes, so that would be ~700 Livewire round-trips an hour
     * per open tab re-rendering byte-identical output.
     */
    protected ?string $pollingInterval = '60s';

    public function getHeading(): ?string
    {
        return __('filami::widgets.chart_heading');
    }

    /** Names the window this chart is showing — the select itself lives next door. */
    public function getDescription(): ?string
    {
        return $this->umamiPeriod()->label();
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

        $period = $this->umamiPeriod();
        $unit = $period->chartUnit();

        try {
            $series = $this->umami()->pageviewSeries($websiteId, $period->start(), now(), $unit);
        } catch (Throwable $exception) {
            report($exception);

            return ['datasets' => [], 'labels' => []];
        }

        // Resolved once: formatLabel() runs per point, up to 90 of them.
        $timezone = config('app.timezone', 'UTC');
        $sessionSeries = collect($series['sessions'])->keyBy('x');
        $labels = [];
        $pageviews = [];
        $sessions = [];

        foreach ($series['pageviews'] as $point) {
            $x = (string) ($point['x'] ?? '');
            $labels[] = $this->formatLabel($x, $unit, $timezone);
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

    protected function formatLabel(string $timestamp, string $unit, string $timezone): string
    {
        try {
            $date = Carbon::parse($timestamp)->timezone($timezone);
        } catch (Throwable) {
            return $timestamp;
        }

        return $unit === 'hour' ? $date->format('H:i') : $date->translatedFormat('d. M');
    }
}
