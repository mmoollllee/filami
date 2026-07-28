<?php

namespace Mmoollllee\Filami\Console\Commands;

use Illuminate\Console\Command;
use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\Jobs\ProvisionUmamiWebsite;
use Mmoollllee\Filami\Jobs\SyncUmamiWebsite;

/**
 * Backfill for existing records: creates Umami websites for every registered
 * model that has none yet. Run once after rolling out the integration.
 *
 * --push additionally re-sends name/domain for records that are already
 * linked, which doubles as the reconcile path: SyncUmamiWebsite drops an id
 * the instance does not know (404) and links the record afresh. That matters
 * when an app previously tracked against a different Umami server — those ids
 * would otherwise be skipped forever.
 */
class SyncCommand extends Command
{
    protected $signature = 'filami:sync
        {--push : Also re-send name/domain for linked records, re-linking any id this instance does not know}
        {--queue : Dispatch jobs to the queue instead of running them synchronously}';

    protected $description = 'Create missing Umami websites for all models registered via Filami::autoProvision()';

    public function handle(): int
    {
        if (! Filami::apiConfigured()) {
            $this->error('Umami is not configured — set UMAMI_URL plus UMAMI_USERNAME/UMAMI_PASSWORD (or UMAMI_API_KEY).');

            return self::FAILURE;
        }

        $models = Filami::provisionedModels();

        if ($models === []) {
            $this->warn('No models registered via Filami::autoProvision().');

            return self::SUCCESS;
        }

        $push = (bool) $this->option('push');

        foreach ($models as $modelClass) {
            $counts = ['provisioned' => 0, 'pushed' => 0, 'skipped' => 0];

            $modelClass::query()->chunkById(100, function ($records) use (&$counts, $push): void {
                foreach ($records as $record) {
                    if (! Filami::passesFilter($record)) {
                        $counts['skipped']++;

                        continue;
                    }

                    if (Filami::websiteId($record) === null) {
                        $this->dispatchJob(new ProvisionUmamiWebsite($record));
                        $counts['provisioned']++;
                    } elseif ($push) {
                        $this->dispatchJob(new SyncUmamiWebsite($record));
                        $counts['pushed']++;
                    }
                }
            });

            $this->info(sprintf(
                '%s: %d provisioned, %d pushed, %d skipped.',
                class_basename($modelClass),
                $counts['provisioned'],
                $counts['pushed'],
                $counts['skipped'],
            ));
        }

        return self::SUCCESS;
    }

    protected function dispatchJob(ProvisionUmamiWebsite|SyncUmamiWebsite $job): void
    {
        if ($this->option('queue')) {
            dispatch($job)->onQueue(config('filami.queue'));

            return;
        }

        dispatch_sync($job);
    }
}
