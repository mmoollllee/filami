<?php

namespace Mmoollllee\Filami\Jobs;

use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\UmamiClient;

/**
 * Deletes an Umami website (including its data). Takes the plain website id
 * because the owning model is usually already gone when this runs.
 */
class DeprovisionUmamiWebsite extends UmamiJob
{
    public function __construct(public string $websiteId) {}

    public function handle(UmamiClient $client): void
    {
        if (! Filami::apiConfigured()) {
            return;
        }

        $client->deleteWebsite($this->websiteId);
    }
}
