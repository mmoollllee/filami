<?php

namespace Mmoollllee\Filami\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Plain model without the trait — resolved via attribute conventions. */
class Site extends Model
{
    protected $guarded = [];
}
