<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\ImageEngine\GpuMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado de la placa para el indicador del encabezado (lo consulta cada 15 s).
 */
class GpuStatusController extends Controller
{
    public function __invoke(Request $request, GpuMonitor $monitor): JsonResponse
    {
        $status = $monitor->current()->toArray();

        // Los detalles técnicos (placa, VRAM, latencia) son solo para el admin.
        if (! $request->user()->isAdmin()) {
            $status = array_intersect_key($status, array_flip(['state', 'online', 'message', 'queue_pending', 'avg_seconds', 'checked_at']));

            // Un usuario no necesita saber por qué falla la configuración.
            if ($status['state'] === 'error') {
                $status['state'] = 'offline';
                $status['message'] = 'La placa no está disponible.';
            }
        }

        $status['my_pending'] = Generation::query()
            ->whereBelongsTo($request->user())
            ->pending()
            ->count();

        return response()->json($status)->header('Cache-Control', 'no-store');
    }
}
