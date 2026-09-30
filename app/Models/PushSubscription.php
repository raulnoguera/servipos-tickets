<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'endpoint', 'public_key', 'auth_token', 'user_agent', 'last_used_at'])]
#[Hidden(['public_key', 'auth_token'])]
class PushSubscription extends Model
{
    /**
     * Servicios push de los navegadores (Chrome/Android, Firefox, Edge, Safari/iPhone).
     * El servidor hace POST al endpoint: aceptar cualquier URL permitiría usarlo
     * para sondear direcciones internas (SSRF).
     */
    private const ALLOWED_HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com'];

    public static function isAllowedEndpoint(mixed $endpoint): bool
    {
        if (! is_string($endpoint) || ! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
