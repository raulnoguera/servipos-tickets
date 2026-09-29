<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'active'])]
class Part extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
