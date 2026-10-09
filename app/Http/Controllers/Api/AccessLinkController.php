<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Enlace de acceso de un solo uso: el administrador lo genera y lo envía (por WhatsApp);
 * el usuario lo abre y crea su propia contraseña. Sirve para el primer ingreso y cuando
 * alguien olvida su contraseña. El administrador nunca conoce la contraseña.
 */
class AccessLinkController extends Controller
{
    private const VALID_HOURS = 48;

    public function __construct(private AuditLogger $audit) {}

    /** Genera el enlace para un usuario (solo administradores). Anula cualquier enlace anterior. */
    public function create(User $user): JsonResponse
    {
        if (! $user->active) {
            throw ValidationException::withMessages(['user' => 'El usuario está desactivado. Actívelo antes de enviarle el acceso.']);
        }
        if (! $user->loginIdentifier()) {
            throw ValidationException::withMessages(['user' => 'Cargue primero el teléfono del técnico: es su usuario para ingresar.']);
        }

        // En la base de datos solo queda la huella del código; el código viaja únicamente en el enlace.
        $token = Str::random(48);
        $expires = now()->addHours(self::VALID_HOURS);
        $user->forceFill(['access_token_hash' => hash('sha256', $token), 'access_token_expires_at' => $expires])->save();
        $this->audit->record('user.access_link', "Enlace de acceso generado para {$user->name}", $user);

        // El código va después de "#": el navegador no lo envía al servidor ni queda en registros o vistas previas.
        $url = url('/acceso').'#'.$token;
        $first = Str::before($user->name, ' ');
        $message = "Hola {$first}, este es su acceso a ServiPos Tickets.\n\n"
            .'Abra este enlace para crear su contraseña (vale por '.self::VALID_HOURS." horas y un solo uso):\n{$url}\n\n"
            ."Después ingresará con su usuario: {$user->loginIdentifier()}";

        return response()->json([
            'url' => $url,
            'expires_at' => $expires->toIso8601String(),
            'login' => $user->loginIdentifier(),
            'whatsapp_url' => $user->whatsappNumber() ? 'https://wa.me/'.$user->whatsappNumber().'?text='.rawurlencode($message) : null,
            'message' => $message,
        ]);
    }

    /** Quién abrió el enlace, para saludarlo antes de que cree su contraseña. */
    public function check(Request $request): JsonResponse
    {
        $user = $this->resolve($request);

        return response()->json(['name' => $user->name, 'login' => $user->loginIdentifier()]);
    }

    /** El usuario crea su contraseña; el enlace queda usado y entra con la sesión iniciada. */
    public function complete(Request $request): UserResource
    {
        abort_unless($request->hasSession(), 400, 'Abra el enlace desde el portal.');
        $user = $this->resolve($request);
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()]]);

        $user->forceFill([
            'password' => $data['password'],
            'access_token_hash' => null,
            'access_token_expires_at' => null,
            'remember_token' => Str::random(60),
            'last_login_at' => now(),
        ])->save();
        // Si era un cambio por contraseña olvidada, sus otros equipos dejan de recibir avisos hasta volver a entrar.
        $user->pushSubscriptions()->delete();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        AuthController::keepSession($request, $user);
        $this->audit->record('user.access_completed', 'Creó su contraseña con el enlace de acceso', $user, actor: $user);

        return new UserResource($user->load('states'));
    }

    private function resolve(Request $request): User
    {
        $token = (string) $request->validate(['token' => ['required', 'string', 'size:48']])['token'];

        return User::query()
            ->where('access_token_hash', hash('sha256', $token))
            ->where('access_token_expires_at', '>', now())
            ->where('active', true)
            ->first()
            ?? throw ValidationException::withMessages(['token' => 'Este enlace ya se usó o venció. Pida uno nuevo al administrador.']);
    }
}
