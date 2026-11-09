<?php

namespace App\Services\ImageEngine;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Estado de la placa cacheado: lo refresca `gpu:probe` cada minuto y, si
 * quedó viejo, la primera visita que lo pida (con un lock para no
 * consultar la placa varias veces a la vez).
 */
class GpuMonitor
{
    public const CACHE_KEY = 'gpu_status';

    public function __construct(private readonly ImageEngine $engine) {}

    public function current(): GpuStatus
    {
        $cached = $this->cached();

        if ($cached && $cached->checkedAt?->gt(CarbonImmutable::now()->subSeconds((int) config('lumora.gpu.status_ttl_seconds', 30)))) {
            return $cached;
        }

        $fresh = Cache::lock('gpu_status_probe', 20)->get(fn () => $this->refresh());

        // Otro request ya está consultando: se devuelve lo último conocido.
        return $fresh ?? $cached ?? new GpuStatus(GpuStatus::OFFLINE, 'Consultando la placa…', checkedAt: CarbonImmutable::now());
    }

    public function refresh(): GpuStatus
    {
        $status = $this->engine->health();
        Cache::forever(self::CACHE_KEY, $status->toArray());

        return $status;
    }

    public function cached(): ?GpuStatus
    {
        $data = Cache::get(self::CACHE_KEY);

        return is_array($data) ? GpuStatus::fromArray($data) : null;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
