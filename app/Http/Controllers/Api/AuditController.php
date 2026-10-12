<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Consulta del registro de auditoría (solo administradores). */
class AuditController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'group' => ['nullable', 'string', 'in:auth,user,catalog,atm,sla,import,export,email'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $term = addcslashes(trim($filters['q'] ?? ''), '%_\\');

        $logs = AuditLog::query()
            ->when($filters['group'] ?? null, fn ($q, $group) => $q->where('action', 'like', $group.'.%'))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('description', 'like', "%{$term}%")
                ->orWhere('user_name', 'like', "%{$term}%")
                ->orWhere('ip', 'like', "%{$term}%")))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'at' => $log->created_at->toIso8601String(),
                'user' => $log->user_name,
                'action' => $log->action,
                'description' => $log->description,
                'meta' => $log->meta,
                'ip' => $log->ip,
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(),
                'from' => $logs->firstItem(), 'to' => $logs->lastItem(), 'total' => $logs->total(), 'per_page' => $logs->perPage(),
            ],
        ]);
    }
}
