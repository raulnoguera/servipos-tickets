<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Versión de la aplicación publicada en el servidor. La app abierta en un teléfono
 * o computadora la compara con la suya para saber si debe actualizarse.
 */
class VersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $file = public_path('build/version.json');
        $build = is_file($file) ? (json_decode((string) file_get_contents($file), true)['build'] ?? null) : null;

        return response()->json(['build' => is_string($build) ? $build : null]);
    }
}
