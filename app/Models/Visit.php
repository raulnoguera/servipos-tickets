<?php

namespace App\Models;

use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Una atención en sitio; equivale a una fila de la "Base General" del Excel. */
#[Fillable([
    'client_uuid', 'ticket_id', 'technician_id', 'fault_type_id', 'constancia_number', 'visit_date',
    'arrived_at', 'finished_at', 'work_done', 'parts_changed', 'atm_condition_id', 'constancia_path',
])]
class Visit extends Model
{
    /** Los tiempos del ciclo se guardan con milisegundos (RF-3.4). */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
            'finished_at' => 'datetime',
            'parts_changed' => 'boolean',
        ];
    }

    /** Milisegundos entre el cierre declarado por el teléfono y su llegada al servidor, si fue un envío tardío. */
    public function lateMs(): ?int
    {
        if (! $this->finished_at || ! $this->created_at) {
            return null;
        }
        $ms = (int) $this->finished_at->diffInMilliseconds($this->created_at);

        return $ms >= TicketService::LATE_REPORT_SECONDS * 1000 ? $ms : null;
    }

    /** Columna DATE: se guarda siempre como AAAA-MM-DD para que los filtros por fecha funcionen igual en SQLite y MySQL. */
    protected function visitDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? CarbonImmutable::parse($value)->startOfDay() : null,
            set: fn (mixed $value) => $value ? CarbonImmutable::parse($value)->toDateString() : null,
        );
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function faultType(): BelongsTo
    {
        return $this->belongsTo(FaultType::class);
    }

    public function atmCondition(): BelongsTo
    {
        return $this->belongsTo(AtmCondition::class);
    }

    public function parts(): BelongsToMany
    {
        return $this->belongsToMany(Part::class, 'visit_parts')->withPivot('quantity');
    }

    /** Repuestos en una sola celda, como en el consolidado: "PICKER x2, FEEDSHAFT x1". */
    public function partsSummary(): string
    {
        return $this->parts->map(fn (Part $p) => "{$p->name} x{$p->pivot->quantity}")->implode(', ');
    }
}
