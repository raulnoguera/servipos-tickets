<?php

namespace App\Models;

use App\Enums\Priority;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Metas de SLA por prioridad, en minutos (null = sin meta). */
#[Fillable(['priority', 'response_minutes', 'resolution_minutes'])]
class SlaPolicy extends Model
{
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
        ];
    }
}
