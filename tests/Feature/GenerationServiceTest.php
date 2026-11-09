<?php

use App\Enums\GenerationStatus;
use App\Jobs\GenerateImage;
use App\Models\AiModel;
use App\Models\AppSetting;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\GenerationService;
use App\Services\Generations\QuotaExceededException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Queue::fake();
    $this->service = app(GenerationService::class);
});

it('crea la generación con el tamaño del aspecto y encola el Job', function () {
    $user = User::factory()->create();

    $generation = $this->service->create($user, ['prompt' => '  un gato  ', 'aspect' => '16:9']);

    expect($generation->prompt)->toBe('un gato')
        ->and([$generation->width, $generation->height])->toBe([1344, 768])
        ->and($generation->aspect)->toBe('16:9')
        ->and($generation->model)->toBe('z-image-turbo')
        ->and($generation->steps)->toBe(9)
        ->and($generation->status)->toBe(GenerationStatus::Queued);
    Queue::assertPushed(GenerateImage::class, fn ($job) => $job->generationId === $generation->id);
});

it('rechaza un aspecto que no existe', function () {
    $this->service->create(User::factory()->create(), ['prompt' => 'x', 'aspect' => '5:1']);
})->throws(ValidationException::class);

it('solo el admin puede pedir tamaño libre y pasos', function () {
    $data = ['prompt' => 'x', 'aspect' => 'custom', 'width' => 2000, 'height' => 3000, 'steps' => 15];

    $user = $this->service->create(User::factory()->create(), $data);
    $admin = $this->service->create(User::factory()->admin()->create(), $data);

    expect([$user->width, $user->height, $user->steps])->toBe([1024, 1024, 9])
        ->and([$admin->width, $admin->height, $admin->steps])->toBe([2000, 2048, 15]);
});

it('respeta la cuota diaria y no cuenta las fallidas', function () {
    $user = User::factory()->create(['daily_quota' => 2]);
    Generation::factory()->for($user)->create();
    Generation::factory()->for($user)->status(GenerationStatus::Failed)->create();
    Generation::factory()->for($user)->create(['created_at' => now()->subDays(2)]);

    expect($this->service->remainingToday($user))->toBe(1);
    $this->service->create($user, ['prompt' => 'x']);
    expect($this->service->remainingToday($user))->toBe(0);

    $this->service->create($user, ['prompt' => 'y']);
})->throws(QuotaExceededException::class, 'límite de 2 imágenes');

it('usa la cuota global si el usuario no tiene una propia', function () {
    AppSetting::set('default_daily_quota', '1');
    $user = User::factory()->create();

    $this->service->create($user, ['prompt' => 'x']);

    expect(fn () => $this->service->create($user, ['prompt' => 'y']))->toThrow(QuotaExceededException::class);
});

it('el admin no tiene límite', function () {
    AppSetting::set('default_daily_quota', '0');
    $admin = User::factory()->admin()->create();

    $this->service->create($admin, ['prompt' => 'x']);

    expect($this->service->remainingToday($admin))->toBeNull();
});

it('un usuario no puede usar un modelo solo para admin', function () {
    AiModel::query()->create(['key' => 'qwen-image', 'label' => 'Qwen', 'admin_only' => true, 'sort' => 2]);

    expect($this->service->create(User::factory()->admin()->create(), ['prompt' => 'x', 'model' => 'qwen-image'])->model)
        ->toBe('qwen-image');

    $this->service->create(User::factory()->create(), ['prompt' => 'x', 'model' => 'qwen-image']);
})->throws(ValidationException::class);
