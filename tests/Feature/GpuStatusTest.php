<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Services\ImageEngine\GatewayEngine;
use App\Services\ImageEngine\GpuMonitor;
use App\Services\ImageEngine\GpuStatus;
use Tests\Support\FakeGateway;

it('ve la placa disponible', function () {
    FakeGateway::install();

    $status = GatewayEngine::fromSettings()->health();

    expect($status->state)->toBe(GpuStatus::AVAILABLE)
        ->and($status->device)->toContain('RTX 3060')
        ->and($status->vramFreeGb)->toBe(10.9)
        ->and($status->avgSeconds)->toBe(16.4);
});

it('ve la placa ocupada si hay cola', function () {
    $gateway = FakeGateway::install();
    $gateway->health['queue']['pending'] = 3;

    $status = GatewayEngine::fromSettings()->health();

    expect($status->state)->toBe(GpuStatus::BUSY)
        ->and($status->message)->toContain('3 en cola');
});

it('ve la placa apagada si no hay conexión o el túnel devuelve 502', function () {
    $gateway = FakeGateway::install();
    $gateway->offline = true;
    expect(GatewayEngine::fromSettings()->health()->state)->toBe(GpuStatus::OFFLINE);

    $gateway->offline = false;
    $gateway->healthStatus = 502;
    expect(GatewayEngine::fromSettings()->health()->state)->toBe(GpuStatus::OFFLINE);
});

it('avisa si la PC está prendida pero ComfyUI cerrado', function () {
    $gateway = FakeGateway::install();
    $gateway->health['status'] = 'degraded';

    $status = GatewayEngine::fromSettings()->health();

    expect($status->state)->toBe(GpuStatus::OFFLINE)
        ->and($status->message)->toContain('ComfyUI');
});

it('marca error si el token no coincide o falta configurar', function () {
    FakeGateway::install();
    config(['services.lumora_gpu.token' => 'otro-token-que-no-va-1234']);
    expect(GatewayEngine::fromSettings()->health()->state)->toBe(GpuStatus::ERROR);

    config(['services.lumora_gpu.url' => null]);
    expect(GatewayEngine::fromSettings()->health()->message)->toContain('Falta configurar');
});

it('usa la URL y el token de Ajustes antes que los del .env', function () {
    FakeGateway::install();
    $token = config('services.lumora_gpu.token');
    config(['services.lumora_gpu.url' => 'https://otro.test', 'services.lumora_gpu.token' => 'x']);

    AppSetting::set('gpu_url', 'https://gpu.test');
    AppSetting::setGpuToken($token);

    expect(AppSetting::get('gpu_token'))->not->toBe($token)
        ->and(GatewayEngine::fromSettings()->health()->state)->toBe(GpuStatus::AVAILABLE);
});

it('gpu:probe guarda el estado en caché', function () {
    FakeGateway::install();

    $this->artisan('gpu:probe')->assertSuccessful();

    expect(app(GpuMonitor::class)->cached()?->state)->toBe(GpuStatus::AVAILABLE);
});

it('el monitor no vuelve a consultar la placa mientras el estado está fresco', function () {
    $gateway = FakeGateway::install();
    $monitor = app(GpuMonitor::class);

    $monitor->current();
    $monitor->current();
    expect($gateway->calls)->toHaveCount(1);

    $this->travel(31)->seconds();
    $monitor->current();
    expect($gateway->calls)->toHaveCount(2);
});

it('un usuario ve el estado resumido, sin detalles técnicos', function () {
    $gateway = FakeGateway::install();
    config(['services.lumora_gpu.token' => 'otro-token-que-no-va-1234']);

    $this->actingAs(User::factory()->create())
        ->getJson(route('gpu.status'))
        ->assertOk()
        ->assertJson(['state' => 'offline', 'online' => false, 'my_pending' => 0])
        ->assertJsonMissingPath('device')
        ->assertJsonMissingPath('latency_ms');
});

it('el admin ve los detalles de la placa', function () {
    FakeGateway::install();

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('gpu.status'))
        ->assertOk()
        ->assertJson(['state' => 'available', 'device' => 'cuda:0 NVIDIA GeForce RTX 3060']);
});

it('una cuenta pendiente no puede consultar la placa', function () {
    $this->actingAs(User::factory()->pending()->create())
        ->getJson(route('gpu.status'))
        ->assertForbidden();
});
