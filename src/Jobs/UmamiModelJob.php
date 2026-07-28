<?php

namespace Mmoollllee\Filami\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\Model;

/**
 * A job acting on one trackable model. Unique per model so a burst of edits
 * cannot queue the same work several times over.
 */
abstract class UmamiModelJob extends UmamiJob implements ShouldBeUnique
{
    /**
     * Without an expiry the unique lock is released only by a completed or
     * permanently failed job — a worker killed mid-run (deploy restart, OOM)
     * would lock its model out of provisioning forever.
     */
    public int $uniqueFor = 3600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Model $model) {}

    public function uniqueId(): string
    {
        return $this->model::class.':'.$this->model->getKey();
    }
}
