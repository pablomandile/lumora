<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\ImageEngine\Exceptions\GenerationRejectedException;
use App\Services\ImageEngine\Exceptions\GpuUnavailableException;
use App\Services\ImageEngine\Exceptions\RemoteJobNotFoundException;
use App\Services\ImageEngine\ImageEngine;
use App\Services\ImageEngine\RemoteJob;
use App\Services\Images\ImageStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Lleva una generación de punta a punta contra el gateway de la PC GPU:
 * la encola allá, consulta el estado, baja el PNG y lo guarda.
 *
 * Cuando hace falta esperar, se vuelve a despachar a sí mismo (un Job nuevo,
 * con los intentos en cero):
 *  - Placa apagada: estado "esperando la placa" y otro intento en 60 s.
 *  - Generación larga: después de unos minutos suelta el worker y vuelve a la cola.
 *
 * Los intentos extra son solo para cuando el worker muere a la mitad (el cron
 * lo corta, se reinicia el server): como guarda remote_job_id, retoma sin
 * volver a pedir la imagen. Una excepción de verdad la da por fallida enseguida.
 */
class GenerateImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 1;

    // Tiene que superar poll_budget_seconds + la descarga de la imagen.
    public int $timeout = 360;

    public function __construct(public string $generationId) {}

    public function handle(ImageEngine $engine, ImageStore $store): void
    {
        $generation = Generation::query()->find($this->generationId);

        if (! $generation || $generation->status->isFinished()) {
            return;
        }

        try {
            if (! $generation->remote_job_id) {
                $this->submit($engine, $generation);
            }

            $this->follow($engine, $store, $generation);
        } catch (GpuUnavailableException $e) {
            $this->waitForGpu($generation, $e->getMessage());
        } catch (RemoteJobNotFoundException) {
            // El gateway se reinició y perdió el trabajo: se vuelve a pedir.
            $generation->forceFill([
                'remote_job_id' => null,
                'status' => GenerationStatus::Queued,
                'progress' => null,
            ])->save();
            self::dispatch($generation->id);
        } catch (GenerationRejectedException $e) {
            $generation->markFailed($e->getMessage());
        }
    }

    public function failed(?Throwable $e): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation && ! $generation->status->isFinished()) {
            $generation->markFailed('Se produjo un error inesperado al generar la imagen.');
        }
    }

    private function submit(ImageEngine $engine, Generation $generation): void
    {
        try {
            $remote = $engine->submit([
                'model' => $generation->model,
                'prompt' => $generation->prompt,
                'negative_prompt' => $generation->negative_prompt,
                'width' => $generation->width,
                'height' => $generation->height,
                'seed' => $generation->seed,
                'steps' => $generation->steps,
            ]);
        } catch (RemoteJobNotFoundException) {
            // Un 404 al encolar: la URL no apunta al gateway de Lumora.
            throw new GpuUnavailableException('La URL configurada no responde como el gateway de Lumora.');
        }

        $generation->forceFill(['remote_job_id' => $remote->id, 'error' => null])->save();
        $this->sync($generation, $remote);
    }

    private function follow(ImageEngine $engine, ImageStore $store, Generation $generation): void
    {
        $deadline = microtime(true) + (int) config('lumora.gpu.poll_budget_seconds', 240);
        $interval = (int) config('lumora.gpu.poll_interval_seconds', 2);

        while (true) {
            $remote = $engine->status($generation->remote_job_id);
            $this->sync($generation, $remote);

            if ($remote->isDone()) {
                $this->finish($engine, $store, $generation, $remote);

                return;
            }

            if ($remote->isFailed()) {
                $generation->markFailed($remote->error ?: 'La placa no pudo generar la imagen.');
                $engine->forget($remote->id);

                return;
            }

            if (microtime(true) >= $deadline) {
                // Hay cola larga en la placa: se suelta el worker y se sigue después.
                self::dispatch($generation->id);

                return;
            }

            Sleep::for($interval)->seconds();
        }
    }

    private function finish(ImageEngine $engine, ImageStore $store, Generation $generation, RemoteJob $remote): void
    {
        $png = $engine->image($remote->id);

        try {
            $store->store($generation, $png);
        } catch (Throwable $e) {
            Log::error('No se pudo guardar la imagen generada', ['generation' => $generation->id, 'error' => $e->getMessage()]);
            $generation->markFailed('No se pudo guardar la imagen.');

            return;
        }

        $generation->forceFill([
            'status' => GenerationStatus::Done,
            'progress' => 1,
            'queue_position' => null,
            'run_seconds' => $remote->runSeconds,
            'finished_at' => now(),
            'error' => null,
        ])->save();

        $engine->forget($remote->id);
    }

    private function sync(Generation $generation, RemoteJob $remote): void
    {
        $attributes = [
            'seed' => $remote->seed ?? $generation->seed,
            'steps' => $remote->steps ?? $generation->steps,
        ];

        if ($remote->status === 'running') {
            $attributes += [
                'status' => GenerationStatus::Running,
                'progress' => $remote->progress,
                'queue_position' => null,
                'started_at' => $generation->started_at ?? now(),
            ];
        } elseif ($remote->status === 'queued') {
            $attributes += [
                'status' => GenerationStatus::Queued,
                'queue_position' => $remote->position,
            ];
        }

        $generation->forceFill($attributes)->save();
    }

    private function waitForGpu(Generation $generation, string $reason): void
    {
        $maxHours = (int) config('lumora.gpu.max_wait_hours', 12);

        if ($generation->created_at?->lt(now()->subHours($maxHours))) {
            $generation->markFailed("La placa no estuvo disponible durante {$maxHours} horas. Probá de nuevo más tarde.");

            return;
        }

        Log::info('Placa no disponible, se reintenta más tarde', ['generation' => $generation->id, 'reason' => $reason]);

        $generation->forceFill([
            'status' => GenerationStatus::WaitingGpu,
            'progress' => null,
            'queue_position' => null,
        ])->save();

        self::dispatch($generation->id)->delay(now()->addSeconds((int) config('lumora.gpu.retry_delay_seconds', 60)));
    }
}
