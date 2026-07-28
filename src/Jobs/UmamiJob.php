<?php

namespace Mmoollllee\Filami\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Shared retry policy for the Umami jobs, so tuning it after an incident is a
 * one-file change. Umami is a remote HTTP service: retry generously, but back
 * off hard enough that an outage does not turn into a hammering loop.
 */
abstract class UmamiJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }
}
