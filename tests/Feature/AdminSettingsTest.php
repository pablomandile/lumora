<?php

use App\Models\AppSetting;
use App\Models\Generation;
use App\Models\Image;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeGateway;

it('el admin ve los ajustes', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Settings')
            ->where('settings.gpu_url_env', 'https://gpu.test')
            ->where('settings.gpu_token_set', false)
            ->where('settings.gpu_token_env_set', true)
            ->where('settings.default_daily_quota', 20)
            ->missing('settings.gpu_token'));
});

it('un usuario común no entra a los ajustes', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.settings.index'))
        ->assertForbidden();
});

it('guarda URL, token encriptado, cuota y retención', function () {
    FakeGateway::install();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.settings.update'), [
            'gpu_url' => 'https://lumora-gpu.bambudev.com.ar/',
            'gpu_token' => 'un-token-nuevo-de-prueba-123',
            'default_daily_quota' => 5,
            'retention_days' => 90,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(AppSetting::gpuUrl())->toBe('https://lumora-gpu.bambudev.com.ar')
        ->and(AppSetting::gpuToken())->toBe('un-token-nuevo-de-prueba-123')
        ->and(AppSetting::get('gpu_token'))->not->toContain('un-token')
        ->and(AppSetting::defaultDailyQuota())->toBe(5)
        ->and(AppSetting::retentionDays())->toBe(90);
});

it('dejar el token vacío no lo borra, y se puede borrar a propósito', function () {
    FakeGateway::install();
    AppSetting::setGpuToken('token-guardado-1234567890');
    $admin = User::factory()->admin()->create();
    $base = ['gpu_url' => '', 'default_daily_quota' => 20, 'retention_days' => 0];

    $this->actingAs($admin)->put(route('admin.settings.update'), [...$base, 'gpu_token' => '']);
    expect(AppSetting::gpuToken())->toBe('token-guardado-1234567890');

    $this->actingAs($admin)->put(route('admin.settings.update'), [...$base, 'clear_gpu_token' => true]);
    expect(AppSetting::gpuToken())->toBe(config('services.lumora_gpu.token'));
});

it('valida la URL y el largo del token', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.settings.update'), [
            'gpu_url' => 'no-es-url',
            'gpu_token' => 'corto',
            'default_daily_quota' => -1,
            'retention_days' => 0,
        ])
        ->assertSessionHasErrors(['gpu_url', 'gpu_token', 'default_daily_quota']);
});

it('probar conexión consulta la placa y avisa con un toast', function () {
    FakeGateway::install();

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('admin.settings.index'))
        ->post(route('admin.settings.test-gpu'))
        ->assertRedirect(route('admin.settings.index'))
        ->assertInertiaFlash('toast.type', 'success');
});

it('images:prune borra las viejas salvo favoritas, solo si hay retención', function () {
    Storage::fake('local');
    $make = function (int $daysAgo, bool $favorite = false) {
        $generation = Generation::factory()->create();
        Storage::disk('local')->put("images/{$generation->id}.png", 'x');

        return Image::query()->forceCreate([
            'generation_id' => $generation->id,
            'user_id' => $generation->user_id,
            'path' => "images/{$generation->id}.png",
            'width' => 1, 'height' => 1, 'bytes' => 1,
            'favorite' => $favorite,
            'created_at' => now()->subDays($daysAgo),
        ]);
    };
    $old = $make(40);
    $oldFavorite = $make(40, true);
    $recent = $make(5);

    $this->artisan('images:prune')->expectsOutputToContain('desactivada');
    expect(Image::count())->toBe(3);

    AppSetting::set('retention_days', '30');
    $this->artisan('images:prune')->assertSuccessful();

    expect(Image::withTrashed()->pluck('id')->all())->toEqualCanonicalizing([$oldFavorite->id, $recent->id]);
    Storage::disk('local')->assertMissing($old->path);
    Storage::disk('local')->assertExists($recent->path);
});
