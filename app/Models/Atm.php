<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Database\Factories\AtmFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['atm_number', 'serial', 'bank_id', 'state_id', 'agency_code', 'agency_name', 'site_type', 'brand', 'model', 'address', 'active'])]
class Atm extends Model
{
    /** @use HasFactory<AtmFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function openTickets(): HasMany
    {
        return $this->tickets()->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled]);
    }
}
