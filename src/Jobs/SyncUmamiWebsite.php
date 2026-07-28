<?php

namespace Mmoollllee\Filami\Jobs;

use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\Support\WebsiteProvisioner;
use Mmoollllee\Filami\UmamiClient;

/**
 * Pushes the current name/domain to the linked Umami website. Doubles as the
 * self-heal path: a model with no id yet gets provisioned, and an id the
 * instance does not know is dropped and re-linked.
 */
class SyncUmamiWebsite extends UmamiModelJob
{
    public function handle(UmamiClient $client, WebsiteProvisioner $provisioner): void
    {
        if (! Filami::apiConfigured()) {
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

        $updated = $client->updateWebsite($websiteId, ['name' => $meta['name'], 'domain' => $meta['domain']]);

        // The website was deleted in the Umami UI, or the id belongs to another
        // instance (e.g. left over from a previous Umami server): re-link
        // instead of failing this job on every future edit forever.
        if ($updated === null) {
            $provisioner->relink($this->model);
        }
    }
}
