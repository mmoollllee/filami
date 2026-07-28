<?php

namespace Mmoollllee\Filami\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Filami\Concerns\HasUmamiWebsite;
use Mmoollllee\Filami\Contracts\UmamiTrackable;

/**
 * The documented starting point: contract plus trait, no overrides. Pins that
 * the trait's conventional bodies stay identical to the fallback used for
 * models that implement neither.
 */
class ConventionalSite extends Model implements UmamiTrackable
{
    use HasUmamiWebsite;

    protected $table = 'sites';

    protected $guarded = [];
}
