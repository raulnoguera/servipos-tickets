<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name'])]
class State extends Model
{
    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
