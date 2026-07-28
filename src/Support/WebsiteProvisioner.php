<?php

namespace Mmoollllee\Filami\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Mmoollllee\Filami\Filami;

/**
 * Adopt-or-create, shared by the provisioning and sync jobs.
 *
 * Creating the website and storing its id are two steps that cannot be made
 * atomic across an HTTP call and a database write, so idempotency comes from
 * the domain lookup instead: whenever a retry, a racing job or a re-run of
 * filami:sync comes back around, the existing website is adopted rather than a
 * second one created for the same domain (Umami allows duplicates).
 */
class WebsiteProvisioner
{
    /** Returns the linked website id, or null when the model has no domain. */
    public function provision(Model $model): ?string
    {
        if (($websiteId = Filami::websiteId($model)) !== null) {
            return $websiteId;
        }

        // Resolved per model: a tenant may report to its own Umami instance.
        $client = Filami::client($model);
        $meta = Filami::websiteMeta($model);

        if (blank($meta['domain'])) {
            Log::warning('filami: skipping Umami provisioning, model has no domain.', [
                'model' => $model::class,
                'key' => $model->getKey(),
            ]);

            return null;
        }

        $website = $client->findWebsiteByDomain($meta['domain'])
            ?? $client->createWebsite($meta['name'], $meta['domain']);

        Filami::storeWebsiteId($model, $websiteId = (string) $website['id']);

        return $websiteId;
    }

    /** Drop a dangling id and link the model afresh. */
    public function relink(Model $model): ?string
    {
        Filami::storeWebsiteId($model, null);

        return $this->provision($model);
    }
}
