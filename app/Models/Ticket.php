<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\SlaLevel;
use App\Enums\TicketStatus;
use App\Models\Scopes\BankScope;
use App\Services\SlaService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number', 'atm_id', 'bank_id', 'fault_type_id', 'description', 'bank_reference', 'status', 'priority', 'sla_zone', 'source',
    'assigned_to', 'opened_by', 'opened_at', 'assigned_at', 'responded_at', 'closed_at', 'confirmed_at', 'confirmed_by', 'duration_ms', 'sla_orange_at', 'sla_critical_at',
])]
#[ScopedBy(BankScope::class)]
class Ticket extends Model
{
    /** Los tiempos del ciclo se guardan con milisegundos (RF-3.4). */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => Priority::class,
            'opened_at' => 'datetime',
            'assigned_at' => 'datetime',
            'responded_at' => 'datetime',
            'closed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'duration_ms' => 'integer',
            'sla_orange_at' => 'datetime',
            'sla_critical_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Cada vez que cambia la apertura o el cajero, se recalcula cuándo pasa a naranja y a crítica.
        static::saving(function (Ticket $ticket) {
            if ($ticket->isDirty(['opened_at', 'atm_id', 'source', 'sla_zone']) || ($ticket->source !== 'import' && ! $ticket->sla_critical_at)) {
                app(SlaService::class)->stamp($ticket);
            }
        });
    }

    /** Tickets en un color del semáforo: los en curso según la hora actual; los cerrados, según su cierre. */
    public function scopeSlaLevel(Builder $query, SlaLevel $level): void
    {
        $now = now();
        $query->where('source', '!=', 'import')->whereNotNull('sla_critical_at')->where(fn (Builder $any) => $any
            ->where(function (Builder $running) use ($level, $now) {
                $running->whereNotIn('status', TicketStatus::stopped());
                match ($level) {
                    SlaLevel::Green => $running->where('sla_orange_at', '>=', $now),
                    SlaLevel::Orange => $running->where('sla_orange_at', '<', $now)->where('sla_critical_at', '>=', $now),
                    SlaLevel::Critical => $running->where('sla_critical_at', '<', $now),
                };
            })
            ->orWhere(function (Builder $done) use ($level) {
                $done->whereIn('status', [TicketStatus::Closed, TicketStatus::PendingClosure])->whereNotNull('closed_at');
                match ($level) {
                    SlaLevel::Green => $done->whereColumn('closed_at', '<=', 'sla_orange_at'),
                    SlaLevel::Orange => $done->whereColumn('closed_at', '>', 'sla_orange_at')->whereColumn('closed_at', '<=', 'sla_critical_at'),
                    SlaLevel::Critical => $done->whereColumn('closed_at', '>', 'sla_critical_at'),
                };
            }));
    }

    /** Tickets de un técnico: asignados a él o en los que registró alguna atención. */
    public function scopeForTechnician(Builder $query, User $technician): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('assigned_to', $technician->id)
            ->orWhereHas('visits', fn ($v) => $v->where('technician_id', $technician->id)));
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

    /** Administrador que confirmó el cierre. */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class)->orderBy('visit_date')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class)->orderBy('created_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->orderBy('created_at');
    }

    /** Usuarios que siguen el ticket y reciben todos sus avisos. */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_watchers');
    }
}
