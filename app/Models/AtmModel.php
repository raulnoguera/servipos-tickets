<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modelo de cajero dentro de una marca (p. ej. Diebold · OPTEVA 562). */
#[Fillable(['atm_brand_id', 'name', 'active'])]
class AtmModel extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(AtmBrand::class, 'atm_brand_id');
    }
}
