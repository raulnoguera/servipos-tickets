<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Models\Scopes\BankScope;
use App\Services\SlaService;
use Database\Factories\AtmFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['atm_number', 'serial', 'bank_id', 'state_id', 'agency_code', 'agency_name', 'site_type', 'zone', 'brand', 'model', 'address', 'active'])]
#[ScopedBy(BankScope::class)]
class Atm extends Model
{
    /** @use HasFactory<AtmFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Al cambiar la zona, los tickets en curso de ese cajero toman las metas nuevas.
        static::updated(function (Atm $atm) {
            if ($atm->wasChanged('zone')) {
                app(SlaService::class)->restamp(atmId: $atm->id);
            }
        });
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
