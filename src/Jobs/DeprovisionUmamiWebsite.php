<?php

namespace Mmoollllee\Filami\Jobs;

use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\UmamiClient;

/**
 * Deletes an Umami website (including its data). Takes the plain website id and
 * endpoint because the owning model is usually already gone when this runs.
 */
class DeprovisionUmamiWebsite extends UmamiJob
{
    public function __construct(
        public string $websiteId,
        public ?string $apiUrl = null,
    ) {}

    public function handle(): void
    {
        if (! Filami::hasCredentials() || blank($this->apiUrl ?? Filami::apiUrl())) {
            return;
        }

        UmamiClient::fromConfig((array) config('filami', []), apiUrl: $this->apiUrl)
            ->deleteWebsite($this->websiteId);
    }
}
