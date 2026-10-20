<?php

namespace App\Models;

use App\Enums\Priority;
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
    'number', 'atm_id', 'bank_id', 'fault_type_id', 'description', 'bank_reference', 'status', 'priority', 'source',
    'assigned_to', 'opened_by', 'opened_at', 'assigned_at', 'responded_at', 'closed_at', 'confirmed_at', 'confirmed_by', 'duration_ms',
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
        ];
    }

    /**
     * Tickets con SLA vencido (llegada o resolución) o, con $atRisk, por vencer (≥ 80 %).
     * Se arma con las metas vigentes de cada prioridad para poder filtrar en la base de datos.
     */
    public function scopeSla(Builder $query, string $state): void
    {
        $now = now();
        $ratio = $state === 'at_risk' ? SlaService::AT_RISK_RATIO : 1;
        $query->where('source', '!=', 'import')->where(function (Builder $any) use ($now, $ratio, $state) {
            foreach (app(SlaService::class)->policies() as $priority => $policy) {
                $any->orWhere(function (Builder $q) use ($priority, $policy, $now, $ratio, $state) {
                    $q->where('priority', $priority)->where(function (Builder $breach) use ($policy, $now, $ratio, $state) {
                        if ($policy->resolution_minutes) {
                            $limit = (int) round($policy->resolution_minutes * $ratio);
                            $breach->orWhere(fn ($r) => $r->whereNotIn('status', TicketStatus::stopped())->where('opened_at', '<', $now->copy()->subMinutes($limit)));
                            if ($state === 'breached') {
                                $breach->orWhere(fn ($r) => $r->whereIn('status', [TicketStatus::Closed, TicketStatus::PendingClosure])->where('duration_ms', '>', $policy->resolution_minutes * 60000));
                            }
                        }
                        if ($policy->response_minutes) {
                            $limit = (int) round($policy->response_minutes * $ratio);
                            $breach->orWhere(fn ($r) => $r->whereNull('responded_at')->whereNotIn('status', TicketStatus::stopped())->where('opened_at', '<', $now->copy()->subMinutes($limit)));
                        }
                    });
                });
            }
        });
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
