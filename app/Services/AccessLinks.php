<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Enlaces de acceso de un solo uso para que un usuario cree su propia contraseña.
 * En la base de datos solo queda la huella del código; el código viaja únicamente
 * en el enlace, después de "#", para que no llegue a registros ni vistas previas.
 */
class AccessLinks
{
    public const VALID_HOURS = 48;

    /**
     * Genera un enlace para el usuario y anula cualquier enlace anterior.
     *
     * @return array{url: string, expires_at: CarbonInterface}
     */
    public function issue(User $user): array
    {
        $token = Str::random(48);
        $expires = now()->addHours(self::VALID_HOURS);
        $user->forceFill(['access_token_hash' => hash('sha256', $token), 'access_token_expires_at' => $expires])->save();

        return ['url' => rtrim((string) config('app.url'), '/').'/acceso#'.$token, 'expires_at' => $expires];
    }
}
