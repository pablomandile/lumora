<?php

use App\Enums\GenerationStatus;
use App\Jobs\GenerateImage;
use App\Models\Generation;
use App\Services\Images\PngMetadata;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGateway;

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
});

function runJob(Generation $generation): Generation
{
    app()->call([new GenerateImage($generation->id), 'handle']);

    return $generation->refresh();
}

it('genera la imagen, la guarda con miniatura y avisa al gateway que la borre', function () {
    $gateway = FakeGateway::install();
    $generation = Generation::factory()->create(['prompt' => 'un faro al atardecer, ñandú']);

    runJob($generation);

    expect($generation->status)->toBe(GenerationStatus::Done)
        ->and($generation->seed)->toBe(123)
        ->and($generation->run_seconds)->toBe(15.2)
        ->and($generation->started_at)->not->toBeNull()
        ->and($generation->image)->not->toBeNull();

    $image = $generation->image;
    expect($image->width)->toBe(64)
        ->and($image->height)->toBe(48)
        ->and($image->user_id)->toBe($generation->user_id)
        ->and($image->path)->toStartWith("images/{$generation->user_id}/")
        ->and($image->path)->toEndWith("{$generation->id}.png");

    Storage::disk('local')->assertExists([$image->path, $image->thumb_path]);
    expect(substr(Storage::disk('local')->get($image->thumb_path), 8, 4))->toBe('WEBP');

    // Se sacó el workflow de ComfyUI y quedaron el prompt y la seed.
    $texts = PngMetadata::readText(Storage::disk('local')->get($image->path));
    expect($texts)->not->toHaveKey('prompt')
        ->and($texts['Description'])->toBe('un faro al atardecer, ñandú')
        ->and($texts['Seed'])->toBe('123');

    expect($gateway->submitted[0])->toMatchArray([
        'model' => 'z-image-turbo',
        'prompt' => 'un faro al atardecer, ñandú',
        'width' => 1024,
        'height' => 1024,
        'steps' => 9,
    ])->and($gateway->calls)->toContain('DELETE /jobs/job-1');
});

it('manda la seed elegida por el usuario', function () {
    $gateway = FakeGateway::install();
    $generation = Generation::factory()->create(['seed' => 42]);

    runJob($generation);

    expect($gateway->submitted[0]['seed'])->toBe(42);
});

it('si la placa está apagada queda esperando y se reintenta en un minuto', function () {
    Queue::fake();
    $gateway = FakeGateway::install();
    $gateway->offline = true;
    $generation = Generation::factory()->create();

    runJob($generation);

    expect($generation->status)->toBe(GenerationStatus::WaitingGpu)
        ->and($generation->remote_job_id)->toBeNull();
    Queue::assertPushed(GenerateImage::class, fn (GenerateImage $job) => $job->generationId === $generation->id && $job->delay !== null);
});

it('si ComfyUI está cerrado (503) también espera a la placa', function () {
    Queue::fake();
    $gateway = FakeGateway::install();
    $gateway->submitResponse = [503, ['detail' => 'ComfyUI no está abierto en la PC.']];
    $generation = Generation::factory()->create();

    runJob($generation);

    expect($generation->status)->toBe(GenerationStatus::WaitingGpu);
    Queue::assertPushed(GenerateImage::class);
});

it('se da por fallida si la placa no aparece en horas', function () {
    Queue::fake();
    $gateway = FakeGateway::install();
    $gateway->offline = true;
    $generation = Generation::factory()->create(['created_at' => now()->subHours(13)]);

    runJob($generation);

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error)->toContain('no estuvo disponible');
    Queue::assertNothingPushed();
});

it('marca como fallida si el gateway rechaza el pedido', function () {
    $gateway = FakeGateway::install();
    $gateway->submitResponse = [422, ['detail' => 'El ancho tiene que ser múltiplo de 16.']];
    $generation = Generation::factory()->create();

    runJob($generation);

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error)->toBe('El ancho tiene que ser múltiplo de 16.');
});

it('informa el error de ComfyUI si la generación falla en la placa', function () {
    $gateway = FakeGateway::install();
    $gateway->script = ['running', 'failed'];
    $generation = Generation::factory()->create();

    runJob($generation);

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error)->toContain('CUDA out of memory')
        ->and($generation->image)->toBeNull();
});

it('vuelve a pedir la imagen si el gateway se reinició y perdió el trabajo', function () {
    Queue::fake();
    $gateway = FakeGateway::install();
    $gateway->lost = true;
    $generation = Generation::factory()->create(['remote_job_id' => 'job-1', 'status' => GenerationStatus::Running]);

    runJob($generation);

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->remote_job_id)->toBeNull();
    Queue::assertPushed(GenerateImage::class);
});

it('con cola larga en la placa suelta el worker y vuelve a la cola', function () {
    Queue::fake();
    config(['lumora.gpu.poll_budget_seconds' => 0]);
    $gateway = FakeGateway::install();
    $gateway->script = ['queued'];
    $generation = Generation::factory()->create();

    runJob($generation);

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->remote_job_id)->toBe('job-1')
        ->and($generation->queue_position)->toBe(2);
    Queue::assertPushed(GenerateImage::class, fn (GenerateImage $job) => $job->delay === null);

    // En la siguiente pasada no se vuelve a encolar en la placa.
    Queue::fake();
    $gateway->script = ['done'];
    runJob($generation);
    expect($generation->status)->toBe(GenerationStatus::Done)
        ->and($gateway->submitted)->toHaveCount(1);
});

it('no hace nada con una generación ya terminada', function () {
    $gateway = FakeGateway::install();
    $generation = Generation::factory()->status(GenerationStatus::Done)->create();

    runJob($generation);

    expect($gateway->calls)->toBe([]);
});

it('si el Job revienta, la generación queda fallida', function () {
    $generation = Generation::factory()->create();

    (new GenerateImage($generation->id))->failed(new RuntimeException('timeout'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed);
});
