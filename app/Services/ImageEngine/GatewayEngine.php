<?php

namespace App\Services\ImageEngine;

use App\Models\AppSetting;
use App\Services\ImageEngine\Exceptions\GenerationRejectedException;
use App\Services\ImageEngine\Exceptions\GpuUnavailableException;
use App\Services\ImageEngine\Exceptions\RemoteJobNotFoundException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente del gateway de la PC GPU (gpu_worker/) detrás del túnel de Cloudflare.
 */
class GatewayEngine implements ImageEngine
{
    public function __construct(
        private readonly ?string $url,
        private readonly ?string $token,
        private readonly int $timeout = 20,
        private readonly int $connectTimeout = 5,
    ) {}

    /** Arma el cliente con lo que haya en Ajustes o, si no, en el .env. */
    public static function fromSettings(): self
    {
        return new self(
            AppSetting::gpuUrl(),
            AppSetting::gpuToken(),
            (int) config('services.lumora_gpu.timeout', 20),
            (int) config('services.lumora_gpu.connect_timeout', 5),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->url) && filled($this->token);
    }

    public function health(): GpuStatus
    {
        $now = CarbonImmutable::now();

        if (! $this->isConfigured()) {
            return new GpuStatus(GpuStatus::ERROR, 'Falta configurar la URL o el token de la placa.', checkedAt: $now);
        }

        $start = hrtime(true);
        try {
            $response = $this->http(timeout: 8)->get('/health');
        } catch (ConnectionException) {
            return new GpuStatus(GpuStatus::OFFLINE, 'La placa está apagada o sin conexión.', checkedAt: $now);
        }
        $latency = (int) round((hrtime(true) - $start) / 1e6);

        if (in_array($response->status(), [401, 403], true)) {
            return new GpuStatus(GpuStatus::ERROR, 'La placa rechazó el token: revisá que sea el mismo en los dos lados.', $latency, checkedAt: $now);
        }

        // 502/530 de Cloudflare: el túnel existe pero el gateway no está corriendo.
        if (! $response->successful() || ! is_array($response->json())) {
            return new GpuStatus(GpuStatus::OFFLINE, 'La placa está apagada o sin conexión.', $latency, checkedAt: $now);
        }

        $data = $response->json();
        $gpu = $data['gpu'] ?? null;
        $pending = (int) ($data['queue']['pending'] ?? 0);

        if (($data['status'] ?? null) !== 'ok') {
            return new GpuStatus(GpuStatus::OFFLINE, 'La PC está prendida, pero ComfyUI no está abierto.', $latency, checkedAt: $now);
        }

        return new GpuStatus(
            state: $pending > 0 ? GpuStatus::BUSY : GpuStatus::AVAILABLE,
            message: $pending > 0 ? "Generando ({$pending} en cola)" : 'Lista para generar',
            latencyMs: $latency,
            queuePending: $pending,
            avgSeconds: isset($data['queue']['avg_seconds']) ? (float) $data['queue']['avg_seconds'] : null,
            device: is_array($gpu) ? ($gpu['device'] ?? null) : null,
            vramFreeGb: is_array($gpu) && isset($gpu['vram_free_gb']) ? (float) $gpu['vram_free_gb'] : null,
            vramTotalGb: is_array($gpu) && isset($gpu['vram_total_gb']) ? (float) $gpu['vram_total_gb'] : null,
            checkedAt: $now,
        );
    }

    public function submit(array $request): RemoteJob
    {
        $payload = array_filter([
            'model' => $request['model'],
            'prompt' => $request['prompt'],
            'negative_prompt' => $request['negative_prompt'] ?? null,
            'width' => $request['width'],
            'height' => $request['height'],
            'seed' => $request['seed'] ?? null,
            'steps' => $request['steps'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return RemoteJob::fromArray($this->send(fn (PendingRequest $http) => $http->post('/generate', $payload))->json());
    }

    public function status(string $remoteId): RemoteJob
    {
        return RemoteJob::fromArray($this->send(fn (PendingRequest $http) => $http->get('/jobs/'.rawurlencode($remoteId)))->json());
    }

    public function image(string $remoteId): string
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->accept('image/png')->timeout(max($this->timeout, 60))->get('/jobs/'.rawurlencode($remoteId).'/image'),
        );

        return $response->body();
    }

    public function forget(string $remoteId): void
    {
        try {
            $this->http()->delete('/jobs/'.rawurlencode($remoteId));
        } catch (Throwable $e) {
            // Si falla, el gateway la borra solo al rato (JOB_TTL_SECONDS).
            Log::info('No se pudo avisar al gateway que borre el trabajo', ['id' => $remoteId, 'error' => $e->getMessage()]);
        }
    }

    private function http(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->url, '/'))
            ->withToken((string) $this->token)
            ->withUserAgent('Lumora')
            ->acceptJson()
            ->timeout($timeout ?? $this->timeout)
            ->connectTimeout($this->connectTimeout);
    }

    /**
     * Manda el pedido con reintentos ante cortes del túnel y traduce los
     * errores HTTP a excepciones del motor.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        if (! $this->isConfigured()) {
            throw new GpuUnavailableException('Falta configurar la URL o el token de la placa.');
        }

        $http = $this->http()->retry(
            3,
            fn (int $attempt) => $attempt * 1000,
            fn (Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [502, 503, 504], true)),
            throw: false,
        );

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw new GpuUnavailableException('No se pudo conectar con la placa.', previous: $e);
        }

        $detail = $this->detail($response);

        return match (true) {
            $response->successful() => $response,
            $response->status() === 404 => throw new RemoteJobNotFoundException($detail ?? 'El trabajo no existe en la placa.'),
            in_array($response->status(), [400, 422], true) => throw new GenerationRejectedException($detail ?? 'La placa rechazó el pedido.'),
            in_array($response->status(), [401, 403], true) => throw new GpuUnavailableException('La placa rechazó el token.'),
            $response->status() === 429 => throw new GpuUnavailableException($detail ?? 'La cola de la placa está llena.'),
            default => throw new GpuUnavailableException('La placa respondió con error '.$response->status().'.'),
        };
    }

    private function detail(Response $response): ?string
    {
        $detail = $response->json('detail');

        if (is_string($detail)) {
            return $detail;
        }

        // Errores de validación de FastAPI: lista de {loc, msg}.
        if (is_array($detail) && isset($detail[0]['msg'])) {
            return (string) $detail[0]['msg'];
        }

        return null;
    }
}
