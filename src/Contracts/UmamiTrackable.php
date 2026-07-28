<?php

namespace Mmoollllee\Filami\Contracts;

/**
 * Implemented by models that own an Umami website. The HasUmamiWebsite trait
 * provides a convention-based default implementation; apps with different
 * column names (e.g. an existing analytics_umamiid column) override the
 * accessors instead of migrating data.
 */
interface UmamiTrackable
{
    public function umamiWebsiteId(): ?string;

    public function setUmamiWebsiteId(?string $websiteId): void;

    /** Display name used when creating the website in Umami. */
    public function umamiWebsiteName(): string;

    /** Primary domain of the tracked site; provisioning is skipped without one. */
    public function umamiWebsiteDomain(): ?string;

    /**
     * Base URL of the Umami instance this model reports to, or null to use the
     * configured default. Lets a tenant point at its own server — with a
     * website id that is enough to track, no env required.
     */
    public function umamiUrl(): ?string;
}
