<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'ticket_id', 'kind', 'title', 'body', 'url', 'read_at', 'created_at'])]
class UserNotification extends Model
{
    public const UPDATED_AT = null;

    /** Días que se conserva el historial de la campana. */
    public const KEEP_DAYS = 60;

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
