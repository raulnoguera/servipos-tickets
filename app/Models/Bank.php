<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'short_name', 'active'])]
class Bank extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function atms(): HasMany
    {
        return $this->hasMany(Atm::class);
    }
}
