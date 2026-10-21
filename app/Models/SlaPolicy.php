<?php

namespace App\Models;

use App\Enums\Zone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Metas de SLA por zona: el ticket está en verde hasta «green» y en naranja hasta
 * «orange»; después pasa a crítica. «unit» indica si son horas o días hábiles.
 */
#[Fillable(['zone', 'unit', 'green', 'orange'])]
class SlaPolicy extends Model
{
    public const HOURS = 'hours';

    public const DAYS = 'days';

    protected function casts(): array
    {
        return [
            'zone' => Zone::class,
            'green' => 'float',
            'orange' => 'float',
        ];
    }
}
