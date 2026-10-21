<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Marca de cajero (Diebold, NCR…), con sus modelos. */
#[Fillable(['name', 'active'])]
class AtmBrand extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function models(): HasMany
    {
        return $this->hasMany(AtmModel::class)->orderBy('name');
    }
}
