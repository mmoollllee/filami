<?php

namespace Mmoollllee\Filami\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Filami\Contracts\UmamiTrackable;

/** Implements the contract directly, mapping onto legacy column names. */
class TrackedSite extends Model implements UmamiTrackable
{
    protected $guarded = [];

    public function umamiWebsiteId(): ?string
    {
        return filled($this->analytics_id) ? (string) $this->analytics_id : null;
    }

    public function setUmamiWebsiteId(?string $websiteId): void
    {
        $this->forceFill(['analytics_id' => $websiteId])->saveQuietly();
    }

    public function umamiWebsiteName(): string
    {
        return (string) ($this->title ?? 'Untitled');
    }

    public function umamiWebsiteDomain(): ?string
    {
        return $this->host;
    }
}
