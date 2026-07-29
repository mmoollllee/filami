<?php

namespace Mmoollllee\Filami\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The reporting window shared by all three dashboard widgets.
 *
 * One place decides what "last 7 days" means, so the stats overview, the chart
 * and the top-pages table can never disagree about the window they label with
 * the same words — the failure mode of the previous setup, where the chart had
 * its own filter and the other two read a fixed day count from config.
 */
enum UmamiPeriod: string
{
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';
    case Quarter = '90d';

    /** Window length. Hours, not days, so the 24h case needs no special casing. */
    public function hours(): int
    {
        return match ($this) {
            self::Day => 24,
            self::Week => 24 * 7,
            self::Month => 24 * 30,
            self::Quarter => 24 * 90,
        };
    }

    public function label(): string
    {
        return __("filami::widgets.filters.{$this->value}");
    }

    public function start(?CarbonInterface $end = null): CarbonInterface
    {
        return ($end ?? Carbon::now())->copy()->subHours($this->hours());
    }

    /** Start of the window immediately before this one, for the trend comparison. */
    public function previousStart(?CarbonInterface $end = null): CarbonInterface
    {
        return ($end ?? Carbon::now())->copy()->subHours($this->hours() * 2);
    }

    /** Chart granularity: hourly within a single day, daily beyond it. */
    public function chartUnit(): string
    {
        return $this === self::Day ? 'hour' : 'day';
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** Never throws: an unknown key (stale session, hand-edited request) falls back. */
    public static function fromKey(?string $key): self
    {
        return self::tryFrom((string) $key) ?? self::default();
    }

    public static function default(): self
    {
        $configured = config('filami.widgets.default_period');

        if (is_string($configured) && ($period = self::tryFrom($configured)) !== null) {
            return $period;
        }

        // filami <= 0.2 configured a plain day count instead. Honouring it keeps
        // a published config from silently changing what the dashboard shows.
        $days = (int) config('filami.widgets.stats_period_days', 0);

        return $days > 0 ? self::fromDays($days) : self::Week;
    }

    /** Smallest case that covers $days, so a legacy 14 widens to 30d rather than narrowing to 7d. */
    protected static function fromDays(int $days): self
    {
        foreach (self::cases() as $case) {
            if ($case->hours() >= $days * 24) {
                return $case;
            }
        }

        return self::Quarter;
    }
}
