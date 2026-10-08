<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registro de auditoría de las acciones sensibles: accesos, gestión de usuarios,
 * catálogos, metas de SLA, importaciones, descargas y accesos denegados.
 * El ciclo de cada ticket ya queda en su propio historial (ticket_events).
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $meta  datos de contexto; nunca contraseñas ni secretos
     */
    public function record(string $action, string $description, ?Model $subject = null, array $meta = [], ?User $actor = null): void
    {
        try {
            $actor ??= auth()->user();
            $request = request();

            AuditLog::create([
                'user_id' => $actor?->id,
                'user_name' => $actor?->name,
                'action' => $action,
                'description' => Str::limit($description, 250, '…'),
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'meta' => $meta ?: null,
                'ip' => $request?->ip(),
                'user_agent' => Str::limit((string) $request?->userAgent(), 250, ''),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // La auditoría nunca debe impedir la operación; el fallo queda en el log.
            Log::error('No se pudo escribir la auditoría', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
