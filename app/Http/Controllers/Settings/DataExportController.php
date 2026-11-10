<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Descargar mis datos": la cuenta y todos los pedidos de imagen en un JSON.
 *
 * Las imágenes no van adentro (pesarían cientos de MB en un hosting
 * compartido): cada una trae el enlace para bajar el original en PNG, que
 * funciona aun sin haber aceptado los términos.
 */
class DataExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = [
            'exportado_el' => now()->toIso8601String(),
            'cuenta' => [
                'nombre' => $user->name,
                'email' => $user->email,
                'email_verificado_el' => $user->email_verified_at?->toIso8601String(),
                'creada_el' => $user->created_at?->toIso8601String(),
                'rol' => $user->role->value,
                'estado' => $user->approval_status->value,
                'cuota_diaria' => $user->daily_quota,
                'tamanio_texto' => $user->tamanio_texto->value,
                'terminos_version' => $user->terminos_version,
                'terminos_aceptados_el' => $user->terminos_aceptados_en?->toIso8601String(),
            ],
            'pedidos' => $user->generations()
                ->with('image')
                ->oldest()
                ->get()
                ->map(fn (Generation $g) => [
                    'id' => $g->id,
                    'creado_el' => $g->created_at?->toIso8601String(),
                    'prompt' => $g->prompt,
                    'modelo' => $g->model,
                    'ancho' => $g->width,
                    'alto' => $g->height,
                    'aspecto' => $g->aspect,
                    'pasos' => $g->steps,
                    'semilla' => $g->seed,
                    'estado' => $g->status->value,
                    'error' => $g->error,
                    'imagen' => $g->image ? [
                        'favorita' => $g->image->favorite,
                        'bytes' => $g->image->bytes,
                        'descargar_png' => route('images.download', [$g->image, 'png']),
                    ] : null,
                ])
                ->all(),
        ];

        $filename = 'lumora-mis-datos-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(
            fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $filename,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store'],
        );
    }
}
