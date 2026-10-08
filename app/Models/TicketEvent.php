<?php

namespace App\Models;

use App\Services\TicketService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ticket_id', 'user_id', 'from_status', 'to_status', 'note', 'created_at', 'received_at'])]
class TicketEvent extends Model
{
    /** Los tiempos del ciclo se guardan con milisegundos (RF-3.4). */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'received_at' => 'datetime'];
    }

    /** Milisegundos entre lo ocurrido (según el teléfono) y lo recibido por el servidor, si fue un envío tardío. */
    public function lateMs(): ?int
    {
        $ms = $this->received_at ? (int) $this->created_at->diffInMilliseconds($this->received_at) : 0;

        return $ms >= TicketService::LATE_REPORT_SECONDS * 1000 ? $ms : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
