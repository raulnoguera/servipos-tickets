<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Día no laborable: no suma tiempo al SLA. */
#[Fillable(['date', 'name'])]
class Holiday extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
