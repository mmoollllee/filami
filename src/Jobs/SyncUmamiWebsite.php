<?php

namespace Mmoollllee\Filami\Jobs;

use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\Support\WebsiteProvisioner;

/**
 * Pushes the current name/domain to the linked Umami website. Doubles as the
 * self-heal path: a model with no id yet gets provisioned, and an id the
 * instance does not know is dropped and re-linked.
 */
class SyncUmamiWebsite extends UmamiModelJob
{
    public function handle(WebsiteProvisioner $provisioner): void
    {
        if (! Filami::apiConfigured($this->model)) {
            return;
        }

        $websiteId = Filami::websiteId($this->model);

        if ($websiteId === null) {
            $provisioner->provision($this->model);

            return;
        }

        $meta = Filami::websiteMeta($this->model);

        if (blank($meta['domain'])) {
            return;
        }

        // Name and domain only. Umami's update is partial, so omitting
        // replayConfig leaves session replay and heatmaps exactly as they were
        // configured in its UI — sending it as null would reset them.
        $updated = Filami::client($this->model)
            ->updateWebsite($websiteId, ['name' => $meta['name'], 'domain' => $meta['domain']]);

        // The website was deleted in the Umami UI, or the id belongs to another
        // instance (e.g. the tenant was pointed at a different Umami server):
        // re-link instead of failing this job on every future edit forever.
        if ($updated === null) {
            $provisioner->relink($this->model);
        }
    }
}
