<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number', 'atm_id', 'bank_id', 'fault_type_id', 'description', 'bank_reference', 'status', 'source',
    'assigned_to', 'opened_by', 'opened_at', 'assigned_at', 'closed_at', 'duration_ms',
])]
class Ticket extends Model
{
    /** Los tiempos del ciclo se guardan con milisegundos (RF-3.4). */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'opened_at' => 'datetime',
            'assigned_at' => 'datetime',
            'closed_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    public function atm(): BelongsTo
    {
        return $this->belongsTo(Atm::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function faultType(): BelongsTo
    {
        return $this->belongsTo(FaultType::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class)->orderBy('visit_date')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class)->orderBy('created_at');
    }
}
