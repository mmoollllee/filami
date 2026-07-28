<?php

namespace Mmoollllee\Filami\Support;

/**
 * Normalized /stats response. Umami v2 wraps every number in {value, prev},
 * v3 returns flat numbers plus a comparison object — both collapse to the
 * plain totals here; previous-period values come from a second (cached)
 * stats call with a shifted window instead.
 */
final class UmamiStats
{
    public function __construct(
        public readonly int $pageviews,
        public readonly int $visitors,
        public readonly int $visits,
        public readonly int $bounces,
        public readonly int $totaltime,
    ) {}

    public static function fromResponse(array $data): self
    {
        $value = function (string $key) use ($data): int {
            $raw = $data[$key] ?? 0;

            return is_array($raw) ? (int) ($raw['value'] ?? 0) : (int) $raw;
        };

        return new self(
            pageviews: $value('pageviews'),
            visitors: $value('visitors'),
            visits: $value('visits'),
            bounces: $value('bounces'),
            totaltime: $value('totaltime'),
        );
    }

    /** Bounce rate in percent, null without any visits. */
    public function bounceRate(): ?int
    {
        if ($this->visits < 1) {
            return null;
        }

        return (int) round(min(100, ($this->bounces / $this->visits) * 100));
    }

    /** Average visit duration in seconds, null without any visits. */
    public function averageVisitSeconds(): ?int
    {
        if ($this->visits < 1) {
            return null;
        }

        return intdiv($this->totaltime, $this->visits);
    }
}
