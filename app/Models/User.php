<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Enums\TicketStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'active', 'notify_scope'])]
#[Hidden(['password', 'remember_token', 'access_token_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
            'access_token_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // El teléfono sin formato se mantiene al día: es el usuario con el que entra el técnico.
        // El correo se guarda en minúsculas: así "Ana@…" y "ana@…" son siempre el mismo usuario.
        static::saving(function (User $user) {
            $user->phone_normalized = self::normalizePhone($user->phone);
            $user->email = $user->email ? mb_strtolower(trim($user->email)) : null;
        });
    }

    /**
     * Teléfono sin formato, para comparar: "0414-123 45 67", "+58 414 1234567" y
     * "4141234567" son el mismo número (4141234567). Null si no parece un teléfono.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '58')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }

    /** Número en formato internacional para enlaces de WhatsApp (58414…). */
    public function whatsappNumber(): ?string
    {
        $number = self::normalizePhone($this->phone);

        return match (true) {
            $number === null => null,
            strlen($number) === 10 => '58'.$number,
            default => $number,
        };
    }

    /** Con qué inicia sesión: el técnico con su teléfono; el resto con su correo. */
    public function loginIdentifier(): ?string
    {
        return $this->role === Role::Technician ? ($this->phone ?: $this->email) : ($this->email ?: $this->phone);
    }

    public function states(): BelongsToMany
    {
        return $this->belongsToMany(State::class);
    }

    /** Bancos que atiende un operador. Sin ninguno, atiende todos. */
    public function banks(): BelongsToMany
    {
        return $this->belongsToMany(Bank::class);
    }

    /** @var list<int>|false|null bancos visibles, calculados una vez por petición (false = aún no calculado) */
    private array|false|null $bankScopeCache = false;

    /**
     * Bancos a los que se limita lo que ve el usuario, o null si ve todos.
     * Solo se limita a los operadores con bancos asignados; el técnico ya ve solo sus tickets.
     *
     * @return list<int>|null
     */
    public function bankScope(): ?array
    {
        if ($this->bankScopeCache === false) {
            $ids = $this->role === Role::Operator ? $this->banks()->pluck('banks.id')->map(fn ($id) => (int) $id)->all() : [];
            $this->bankScopeCache = $ids === [] ? null : $ids;
        }

        return $this->bankScopeCache;
    }

    public function forgetBankScope(): void
    {
        $this->bankScopeCache = false;
    }

    public function canSeeBank(?int $bankId): bool
    {
        $banks = $this->bankScope();

        return $banks === null || ($bankId !== null && in_array($bankId, $banks, true));
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }

    public function assignedOpenTickets(): HasMany
    {
        return $this->assignedTickets()->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled]);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function inbox(): HasMany
    {
        return $this->hasMany(UserNotification::class)->latest('created_at')->latest('id');
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function scopeTechnicians(Builder $query): void
    {
        $query->where('role', Role::Technician);
    }
}
