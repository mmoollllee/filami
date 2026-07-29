<?php

namespace Mmoollllee\Filami\Concerns;

use Mmoollllee\Filami\Filami;

/**
 * Conventional {@see \Mmoollllee\Filami\Contracts\UmamiTrackable} bodies: the
 * website id lives in an umami_website_id column, name and domain come from
 * the usual attributes. Pair it with the contract and override only what your
 * schema names differently:
 *
 *     class Tenant extends Model implements UmamiTrackable
 *     {
 *         use HasUmamiWebsite;
 *
 *         public function umamiWebsiteDomain(): ?string
 *         {
 *             return $this->host;
 *         }
 *     }
 *
 * The bodies delegate to Filami rather than restating the conventions, so a
 * model with the trait and a model without can never drift apart.
 */
trait HasUmamiWebsite
{
    public function umamiWebsiteId(): ?string
    {
        return Filami::conventionalWebsiteId($this);
    }

    public function setUmamiWebsiteId(?string $websiteId): void
    {
        Filami::storeConventionally($this, $websiteId);
    }

    public function umamiWebsiteName(): string
    {
        return Filami::conventionalName($this);
    }

    public function umamiWebsiteDomain(): ?string
    {
        return Filami::conventionalDomain($this);
    }

    public function umamiUrl(): ?string
    {
        return Filami::conventionalUrl($this);
    }

    public function umamiRecorderEnabled(): bool
    {
        return Filami::conventionalRecorder($this);
    }
}
