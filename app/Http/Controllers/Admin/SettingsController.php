<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Generation;
use App\Models\Image;
use App\Services\ImageEngine\GpuMonitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(GpuMonitor $monitor): Response
    {
        return Inertia::render('admin/Settings', [
            'settings' => [
                'gpu_url' => AppSetting::get('gpu_url'),
                'gpu_url_env' => config('services.lumora_gpu.url'),
                'gpu_token_set' => AppSetting::get('gpu_token') !== null,
                'gpu_token_env_set' => filled(config('services.lumora_gpu.token')),
                'default_daily_quota' => AppSetting::defaultDailyQuota(),
                'retention_days' => AppSetting::retentionDays(),
            ],
            'gpu' => $monitor->cached()?->toArray(),
            'stats' => [
                'images' => Image::query()->count(),
                'bytes' => (int) Image::withTrashed()->sum('bytes'),
                'pending' => Generation::query()->pending()->count(),
                'waiting_gpu' => Generation::query()->where('status', GenerationStatus::WaitingGpu->value)->count(),
                'failed_today' => Generation::query()->where('status', GenerationStatus::Failed->value)->where('created_at', '>=', now()->subDay())->count(),
            ],
        ]);
    }

    public function update(Request $request, GpuMonitor $monitor): RedirectResponse
    {
        $data = $request->validate([
            'gpu_url' => ['nullable', 'url:https,http', 'max:255'],
            'gpu_token' => ['nullable', 'string', 'min:16', 'max:255'],
            'clear_gpu_token' => ['boolean'],
            'default_daily_quota' => ['required', 'integer', 'min:0', 'max:10000'],
            'retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ], [
            'gpu_url.url' => 'La URL tiene que empezar con https:// (o http:// para pruebas).',
            'gpu_token.min' => 'El token tiene que tener al menos 16 caracteres.',
        ]);

        $urlChanged = ($data['gpu_url'] ?? null) !== AppSetting::get('gpu_url');

        AppSetting::set('gpu_url', filled($data['gpu_url'] ?? null) ? rtrim($data['gpu_url'], '/') : null);
        AppSetting::set('default_daily_quota', (string) $data['default_daily_quota']);
        AppSetting::set('retention_days', (string) $data['retention_days']);

        // El token no se muestra nunca: vacío = dejar el que está.
        if ($request->boolean('clear_gpu_token')) {
            AppSetting::setGpuToken(null);
        } elseif (filled($data['gpu_token'] ?? null)) {
            AppSetting::setGpuToken($data['gpu_token']);
        }

        if ($urlChanged || filled($data['gpu_token'] ?? null) || $request->boolean('clear_gpu_token')) {
            $monitor->refresh();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ajustes guardados.']);

        return back();
    }

    /** Prueba la conexión con la placa ahora mismo. */
    public function testGpu(GpuMonitor $monitor): RedirectResponse
    {
        $status = $monitor->refresh();

        Inertia::flash('toast', [
            'type' => $status->isOnline() ? 'success' : 'error',
            'message' => $status->isOnline()
                ? "Conectado con la placa ({$status->latencyMs} ms): {$status->message}"
                : $status->message,
        ]);

        return back();
    }
}
