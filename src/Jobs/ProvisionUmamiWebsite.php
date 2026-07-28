<?php

namespace Mmoollllee\Filami\Jobs;

use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\Support\WebsiteProvisioner;

/** Links a model to its Umami website, creating it when it does not exist yet. */
class ProvisionUmamiWebsite extends UmamiModelJob
{
    public function handle(WebsiteProvisioner $provisioner): void
    {
        if (! Filami::apiConfigured($this->model)) {
            return;
        }

        $provisioner->provision($this->model);
    }
}
