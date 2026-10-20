<?php

namespace App\Models;

use App\Models\Scopes\BankScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Correo de reporte de falla recibido en el buzón de la empresa. */
#[Fillable(['message_id', 'from_email', 'from_name', 'subject', 'sent_at', 'body', 'status', 'trusted', 'reason', 'parsed', 'atm_id', 'ticket_id', 'handled_by', 'handled_at'])]
#[ScopedBy(BankScope::class)]
class InboundEmail extends Model
{
    /** Abrió un ticket (solo, o a mano desde la bandeja). */
    public const TICKET = 'ticket';

    /** Se agregó como comentario a un ticket que el cajero ya tenía abierto. */
    public const FOLLOW_UP = 'seguimiento';

    /** Necesita que una persona decida. */
    public const REVIEW = 'revisar';

    public const IGNORED = 'ignorado';

    public const STATUSES = [self::TICKET, self::FOLLOW_UP, self::REVIEW, self::IGNORED];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'handled_at' => 'datetime', 'parsed' => 'array', 'trusted' => 'boolean'];
    }

    public function atm(): BelongsTo
    {
        return $this->belongsTo(Atm::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
