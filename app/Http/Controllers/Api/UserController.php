<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Usuarios y maestro de técnicos (RF-1.3). */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with(['states', 'banks'])
            ->withCount('assignedOpenTickets')
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', $role))
            ->orderBy('name')
            ->get();

        return UserResource::collection($users);
    }

    public function store(SaveUserRequest $request, AuditLogger $audit): UserResource
    {
        $data = $request->validated();
        // Sin contraseña: queda una aleatoria que nadie conoce, hasta que el usuario cree la suya con el enlace de acceso.
        $data['password'] = $data['password'] ?? Str::password(32);
        $user = User::create($data + ['email_verified_at' => now()]);
        $user->states()->sync($data['state_ids'] ?? []);
        $user->banks()->sync($user->role === Role::Operator ? ($data['bank_ids'] ?? []) : []);
        $audit->record('user.created', "Usuario creado: {$user->name} ({$user->role->label()})", $user, ['usuario' => $user->loginIdentifier()]);

        return new UserResource($user->load(['states', 'banks']));
    }

    public function update(SaveUserRequest $request, User $user, AuditLogger $audit): UserResource
    {
        $data = $request->validated();
        if ($user->is($request->user()) && (($data['active'] ?? true) === false || $data['role'] !== $user->role->value)) {
            throw ValidationException::withMessages(['role' => 'No puede desactivarse ni cambiar su propio rol.']);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $before = $user->only(['name', 'email', 'phone', 'active']) + ['role' => $user->role->value, 'banks' => $user->banks()->orderBy('name')->pluck('name')->implode(', ')];
        $user->update($data);
        $user->states()->sync($data['state_ids'] ?? []);
        $user->banks()->sync($user->role === Role::Operator ? ($data['bank_ids'] ?? []) : []);

        // Al desactivar o cambiar la contraseña: se invalida "recordarme" en todos sus
        // equipos y dejan de llegar notificaciones a sus teléfonos (puede ser un equipo
        // perdido); el usuario las reactiva al volver a entrar.
        $passwordChanged = $user->wasChanged('password');
        if ($passwordChanged || $user->wasChanged('active')) {
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        }
        if (! $user->active || $passwordChanged) {
            $user->pushSubscriptions()->delete();
        }

        $changes = $this->changes($before, $user, $passwordChanged);
        if ($changes) {
            $audit->record('user.updated', "Usuario modificado: {$user->name} · ".implode(', ', array_keys($changes)), $user, $changes);
        }

        return new UserResource($user->load(['states', 'banks']));
    }

    /**
     * Qué cambió, para la auditoría. De la contraseña solo se anota que cambió.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private function changes(array $before, User $user, bool $passwordChanged): array
    {
        $labels = ['name' => 'nombre', 'email' => 'correo', 'phone' => 'teléfono', 'active' => 'activo', 'role' => 'rol', 'banks' => 'bancos'];
        $after = $user->only(['name', 'email', 'phone', 'active']) + ['role' => $user->role->value, 'banks' => $user->banks()->orderBy('name')->pluck('name')->implode(', ')];
        $changes = [];
        foreach ($labels as $field => $label) {
            if ($before[$field] != $after[$field]) {
                $changes[$label] = ['antes' => $before[$field], 'ahora' => $after[$field]];
            }
        }
        if ($passwordChanged) {
            $changes['contraseña'] = 'cambiada';
        }

        return $changes;
    }
}
