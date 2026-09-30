<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Campana de avisos: historial del usuario y marcas de leído. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->inbox()->limit(min(100, (int) $request->query('limit', 40)))->get();

        return response()->json([
            'unread' => $request->user()->inbox()->whereNull('read_at')->count(),
            'data' => $items->map(fn (UserNotification $n) => [
                'id' => $n->id,
                'kind' => $n->kind,
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->url,
                'read' => $n->read_at !== null,
                'at' => $n->created_at->toIso8601String(),
            ]),
        ]);
    }

    /** Solo el contador, para consultarlo seguido sin traer la lista. */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['unread' => $request->user()->inbox()->whereNull('read_at')->count()]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $request->user()->inbox()->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->unread($request);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->inbox()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }
}
