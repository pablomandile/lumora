<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Simula el gateway de la PC GPU (gpu_worker) con Http::fake.
 */
class FakeGateway
{
    public bool $offline = false;

    /** Respuesta a POST /generate: null = 202 normal, o [status, body]. */
    public ?array $submitResponse = null;

    /** Estados que va devolviendo GET /jobs/{id}, uno por consulta (el último se repite). */
    public array $script = ['running', 'done'];

    public ?string $failError = null;

    /** GET /jobs/{id} responde 404 (el gateway se reinició). */
    public bool $lost = false;

    public array $health = [
        'status' => 'ok',
        'queue' => ['pending' => 0, 'running' => false, 'avg_seconds' => 16.4],
        'gpu' => ['device' => 'cuda:0 NVIDIA GeForce RTX 3060', 'vram_total_gb' => 12.0, 'vram_free_gb' => 10.9],
        'comfyui' => 'ok',
    ];

    public int $healthStatus = 200;

    /** @var list<string> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $submitted = [];

    private int $polls = 0;

    public string $png;

    /** El token que acepta (el del .env de testing al momento de crearlo). */
    public string $token;

    public static function install(): self
    {
        $fake = new self;
        $fake->png = fakeComfyPng();
        $fake->token = (string) config('services.lumora_gpu.token');
        Http::fake(fn (Request $request) => $fake->handle($request));

        return $fake;
    }

    public function handle(Request $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $this->calls[] = $request->method().' '.$path;

        if ($this->offline) {
            return Http::failedConnection('offline');
        }

        if ($request->header('Authorization')[0] !== 'Bearer '.$this->token) {
            return Http::response(['detail' => 'Token inválido.'], 401);
        }

        return match (true) {
            $path === '/health' => Http::response($this->health, $this->healthStatus),
            $path === '/generate' => $this->submit($request),
            $path === '/jobs/job-1' && $request->method() === 'DELETE' => Http::response(null, 204),
            $path === '/jobs/job-1' => $this->status(),
            $path === '/jobs/job-1/image' => Http::response($this->png, 200, ['Content-Type' => 'image/png', 'X-Seed' => '123']),
            default => Http::response(['detail' => 'Not Found'], 404),
        };
    }

    private function submit(Request $request)
    {
        $this->submitted[] = $request->data();

        if ($this->submitResponse) {
            return Http::response($this->submitResponse[1], $this->submitResponse[0]);
        }

        return Http::response(['id' => 'job-1', 'status' => 'queued', 'seed' => 123, 'steps' => 9, 'position' => 1], 202);
    }

    private function status()
    {
        if ($this->lost) {
            return Http::response(['detail' => 'No existe ese trabajo.'], 404);
        }

        $state = $this->script[min($this->polls, count($this->script) - 1)];
        $this->polls++;

        return Http::response(match ($state) {
            'queued' => ['id' => 'job-1', 'status' => 'queued', 'seed' => 123, 'steps' => 9, 'position' => 2],
            'running' => ['id' => 'job-1', 'status' => 'running', 'seed' => 123, 'steps' => 9, 'progress' => 0.5],
            'done' => ['id' => 'job-1', 'status' => 'done', 'seed' => 123, 'steps' => 9, 'progress' => 1, 'run_seconds' => 15.2],
            'failed' => ['id' => 'job-1', 'status' => 'failed', 'seed' => 123, 'steps' => 9, 'error' => $this->failError ?? 'Error en ComfyUI (KSampler): CUDA out of memory'],
        });
    }
}
