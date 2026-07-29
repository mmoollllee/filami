<?php

namespace Mmoollllee\Filami\Filament\Widgets;

use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Mmoollllee\Filami\Filament\Widgets\Concerns\InteractsWithUmami;
use Throwable;

/**
 * Shared base for the Umami widgets that render a metric as a paginated table.
 *
 * Umami's /metrics answers a ranked list in one response. It does accept an
 * offset, but paging server-side would mean a request per page — and a fresh
 * ranking each time, since the ordering is by a count that keeps moving. So
 * one call fetches the whole list and the table pages it here.
 *
 * The subclass supplies a heading, its columns and one {@see fetchRows()} call;
 * everything else — the per-request memo, the failure state, the paginator and
 * the share-of-busiest-row column — lives here, because two copies of it had
 * already drifted apart before this class existed.
 */
abstract class UmamiTableWidget extends TableWidget
{
    use InteractsWithUmami;

    protected int|string|array $columnSpan = 1;

    /** Per-request memo: the table asks for records more than once per render. */
    protected ?array $cachedRows = null;

    /** Set when the API call failed, so the empty state can say why. */
    protected bool $isUnreachable = false;

    /** @return list<array<string, mixed>> */
    abstract protected function rows(): array;

    /**
     * Pagination, polling and the two empty states, identical for every
     * subclass. $emptyHeading keeps each widget's whole empty state in its own
     * table() rather than in a hook method two classes away.
     */
    protected function configureUmamiTable(Table $table, ?string $emptyHeading = null): Table
    {
        return $table
            // A Closure, NOT a plain string: Filament caches the Table object,
            // and a value evaluated at build time is frozen before the period
            // listener runs — the caption would name a different window than
            // the rows below it.
            ->description(fn (): string => $this->umamiPeriod()->label())
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->paginateRows($page, $recordsPerPage))
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10, 25])
            ->emptyStateHeading(fn (): string => $this->isUnreachable
                ? __('filami::widgets.unreachable')
                : ($emptyHeading ?? __('filami::widgets.no_data')))
            // Deliberately shorter than cache.active_ttl would suggest: the
            // responses underneath are cached for minutes.
            ->poll('60s');
    }

    /** A window change invalidates the rows, the failure state and the page numbers. */
    protected function afterUmamiPeriodChanged(): void
    {
        $this->cachedRows = null;
        // Without this a single failed window keeps the table claiming Umami
        // is unreachable for every window the reader switches to afterwards.
        $this->isUnreachable = false;

        $this->resetPage($this->getTablePaginationPageName());
    }

    protected function paginateRows(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $rows = $this->cachedRows ??= $this->rows();

        return new LengthAwarePaginator(
            // preserve_keys: the record key Filament derives from the array key
            // must stay unique across pages, not restart at 0 on every one.
            items: array_slice($rows, ($page - 1) * $recordsPerPage, $recordsPerPage, preserve_keys: true),
            total: count($rows),
            perPage: $recordsPerPage,
            currentPage: $page,
        );
    }

    /**
     * Runs $fetch with the resolved website id and turns any failure into the
     * empty state rather than a broken dashboard. The id is guaranteed
     * non-blank inside the callback, so subclasses need no second check.
     *
     * @param  callable(string): list<array{x: string, y: int}>  $fetch
     * @return list<array{x: string, y: int}>
     */
    protected function fetchRows(callable $fetch): array
    {
        $websiteId = $this->umamiWebsiteId();

        if (blank($websiteId)) {
            return [];
        }

        try {
            // UmamiClient already normalises the wire format (bare list vs.
            // {data: […]} envelope), so what comes back here is a list.
            return $fetch($websiteId);
        } catch (Throwable $exception) {
            report($exception);

            $this->isUnreachable = true;

            return [];
        }
    }

    /**
     * Relabels rows and adds a `share` percentage.
     *
     * The share is relative to the busiest row, not to total traffic: a site
     * whose top row holds 4 % of everything would otherwise render every bar
     * as an invisible sliver.
     *
     * $from names the source keys, so a caller whose rows are already labelled
     * does not have to push them back through Umami's {x, y} shape first.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{0: string, 1: string}  $from
     * @return list<array<string, mixed>>
     */
    protected function withShare(
        array $rows,
        string $labelKey,
        string $countKey,
        array $from = ['x', 'y'],
        ?string $blankLabel = null,
    ): array {
        [$sourceLabel, $sourceCount] = $from;

        $max = max(1, ...array_map(fn (array $row): int => (int) ($row[$sourceCount] ?? 0), $rows ?: [[]]));
        $shaped = [];

        foreach ($rows as $row) {
            $label = ($row[$sourceLabel] ?? '') !== '' ? (string) $row[$sourceLabel] : (string) $blankLabel;

            if ($label === '') {
                continue;
            }

            $count = (int) ($row[$sourceCount] ?? 0);

            $shaped[] = [
                $labelKey => $label,
                $countKey => $count,
                'share' => round($count / $max * 100, 1),
            ];
        }

        return $shaped;
    }
}
