<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/** Usuarios y maestro de técnicos (RF-1.3). */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with('states')
            ->withCount('assignedOpenTickets')
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', $role))
            ->orderBy('name')
            ->get();

        return UserResource::collection($users);
    }

    public function store(SaveUserRequest $request): UserResource
    {
        $data = $request->validated();
        $user = User::create($data + ['email_verified_at' => now()]);
        $user->states()->sync($data['state_ids'] ?? []);

        return new UserResource($user->load('states'));
    }

    public function update(SaveUserRequest $request, User $user): UserResource
    {
        $data = $request->validated();
        if ($user->is($request->user()) && (($data['active'] ?? true) === false || $data['role'] !== $user->role->value)) {
            throw ValidationException::withMessages(['role' => 'No puede desactivarse ni cambiar su propio rol.']);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        $user->states()->sync($data['state_ids'] ?? []);

        return new UserResource($user->load('states'));
    }
}
