<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\PushSubscription;
use App\Services\PushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PushSubscriptionController extends Controller
{
    /** Clave pública VAPID para que el navegador cree la suscripción. */
    public function config(PushNotifier $push): JsonResponse
    {
        return response()->json([
            'enabled' => $push->isEnabled(),
            'public_key' => $push->isEnabled() ? config('services.webpush.public_key') : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                if (! PushSubscription::isAllowedEndpoint($value)) {
                    $fail('El servicio de notificaciones del dispositivo no es válido.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        // Si el dispositivo ya estaba registrado a nombre de otro usuario (otra sesión en
        // el mismo teléfono), pasa al actual solo si presenta las mismas claves del navegador:
        // conocer el endpoint no basta para quitarle los avisos a otra persona.
        $existing = PushSubscription::firstWhere('endpoint', $data['endpoint']);
        if ($existing && $existing->user_id !== $request->user()->id && ! hash_equals($existing->auth_token, $data['keys']['auth'])) {
            abort(409, 'Este dispositivo ya está registrado.');
        }
        PushSubscription::updateOrCreate(['endpoint' => $data['endpoint']], [
            'user_id' => $request->user()->id,
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string']]);
        $request->user()->pushSubscriptions()->where('endpoint', $request->input('endpoint'))->delete();

        return response()->json(['ok' => true]);
    }

    /** Qué avisos de tickets recibe el usuario (operadores y administradores). */
    public function preferences(Request $request): UserResource
    {
        $data = $request->validate([
            'notify_scope' => ['required', Rule::in([PushNotifier::SCOPE_ALL, PushNotifier::SCOPE_MINE, PushNotifier::SCOPE_NONE])],
        ]);
        $request->user()->update($data);

        return new UserResource($request->user()->load('states'));
    }

    /** Envía una notificación de prueba a los dispositivos del usuario. */
    public function test(Request $request, PushNotifier $push): JsonResponse
    {
        $delivered = $push->send($request->user(), [
            'title' => 'Notificaciones activadas',
            'body' => 'Así le avisaremos cuando le asignen un ticket.',
            'url' => '/',
            'tag' => 'prueba',
        ], store: false);

        return response()->json(['delivered' => $delivered]);
    }
}
