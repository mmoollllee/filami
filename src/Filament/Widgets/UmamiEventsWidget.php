<?php

namespace Mmoollllee\Filami\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Throwable;

/**
 * Custom events recorded in the shared reporting window — form submissions,
 * phone and mail clicks, whatever the app reports through
 * <x-filami::events />.
 *
 * Counts alone rarely answer the question that prompts someone to look: an
 * event carrying properties can be broken down by any of them, so
 * "contact-form-submit ×34" becomes "…of which 12 were about the 22m platform".
 * The values behind that breakdown are fetched only when the modal opens; the
 * cheap "does this event have properties at all" list is fetched once per
 * render and memoised, because the row actions ask it per row.
 */
class UmamiEventsWidget extends UmamiTableWidget
{
    protected static ?int $sort = 33;

    /**
     * eventName => property names, for the whole window. Memoised per request:
     * the breakdown action's visible() closure runs once per rendered row, and
     * without this each row rebuilt a client and re-walked the whole list.
     *
     * @var array<string, list<string>>|null
     */
    protected ?array $cachedProperties = null;

    public function table(Table $table): Table
    {
        return $this->configureUmamiTable($table, __('filami::widgets.no_events'))
            ->heading(__('filami::widgets.events'))
            ->columns([
                TextColumn::make('event')
                    ->label(__('filami::widgets.event_name'))
                    ->weight('medium')
                    ->limit(48)
                    ->tooltip(fn (array $record): string => $record['event']),
                ViewColumn::make('share')
                    ->label('')
                    ->view('filami::widgets.share-bar')
                    ->grow(false),
                TextColumn::make('count')
                    ->label(__('filami::widgets.event_count'))
                    ->numeric()
                    ->alignEnd(),
            ])
            ->recordActions([
                Action::make('breakdown')
                    ->label(__('filami::widgets.breakdown'))
                    ->icon(Heroicon::OutlinedChartPie)
                    ->link()
                    ->modalHeading(fn (array $record): string => $record['event'])
                    ->modalContent(fn (array $record): View => view('filami::widgets.event-breakdown', [
                        'properties' => $this->breakdown($record['event']),
                        'period' => $this->umamiPeriod()->label(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('filami::widgets.close'))
                    // Nothing to show for an event that carries no properties.
                    ->visible(fn (array $record): bool => $this->propertyNamesFor($record['event']) !== []),
            ])
            ->emptyStateDescription(fn (): ?string => $this->isUnreachable ? null : __('filami::widgets.no_events_hint'))
            ->emptyStateIcon(Heroicon::OutlinedCursorArrowRays);
    }

    protected function afterUmamiPeriodChanged(): void
    {
        parent::afterUmamiPeriodChanged();

        $this->cachedProperties = null;
    }

    /** @return list<array{event: string, count: int, share: float}> */
    protected function rows(): array
    {
        $events = $this->fetchRows(fn (string $websiteId): array => $this->umami()->events(
            $websiteId,
            $this->umamiPeriod()->start(),
            now(),
        ));

        return $this->withShare($events, 'event', 'count');
    }

    /**
     * Property values per property of one event.
     *
     * @return array<string, list<array{value: string, total: int}>>
     */
    protected function breakdown(string $eventName): array
    {
        $websiteId = $this->umamiWebsiteId();

        if (blank($websiteId)) {
            return [];
        }

        $period = $this->umamiPeriod();
        // Hoisted: a per-iteration now() can straddle a cache-window boundary
        // mid-loop and mint a fresh key for the later properties, and a
        // per-iteration client() re-reads the whole config array each time.
        $end = now();
        $client = $this->umami();
        $breakdown = [];

        foreach ($this->propertyNamesFor($eventName) as $propertyName) {
            try {
                $values = $client->eventPropertyValues(
                    $websiteId,
                    $eventName,
                    $propertyName,
                    $period->start($end),
                    $end,
                );
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if ($values !== []) {
                // Already labelled value/total — no round trip through {x, y}.
                $breakdown[$propertyName] = $this->withShare($values, 'value', 'total', from: ['value', 'total']);
            }
        }

        return $breakdown;
    }

    /** @return list<string> */
    protected function propertyNamesFor(string $eventName): array
    {
        return $this->propertyMap()[$eventName] ?? [];
    }

    /** @return array<string, list<string>> */
    protected function propertyMap(): array
    {
        if ($this->cachedProperties !== null) {
            return $this->cachedProperties;
        }

        $websiteId = $this->umamiWebsiteId();

        if (blank($websiteId)) {
            return $this->cachedProperties = [];
        }

        try {
            $properties = $this->umami()->eventProperties($websiteId, $this->umamiPeriod()->start(), now());
        } catch (Throwable) {
            // Deliberately not reported: older Umami builds have no event-data
            // endpoints at all, and this runs on every dashboard render.
            return $this->cachedProperties = [];
        }

        return $this->cachedProperties = collect($properties)
            ->groupBy('eventName')
            ->map(fn ($rows): array => $rows->pluck('propertyName')->unique()->values()->all())
            ->all();
    }
}
