<?php

use App\Enums\GenerationStatus;
use App\Http\Middleware\AceptoTerminos;
use App\Models\Generation;
use App\Models\Image;
use App\Models\User;
use App\Services\Images\ImageStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * Términos y privacidad: una versión vigente en config('legales.version'), la
 * constancia en `users`, y el middleware AceptoTerminos que frena a quien no la
 * aceptó, salvo para leer, salir, llevarse sus datos y borrar su cuenta.
 */

function datosDeRegistro(array $extra = []): array
{
    return [
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$extra,
    ];
}

beforeEach(fn () => Notification::fake());

it('no deja registrarse sin aceptar', function () {
    $this->post(route('register.store'), datosDeRegistro())
        ->assertSessionHasErrors('terminos');

    $this->assertGuest();
});

it('al registrarse con la casilla deja la constancia', function () {
    $this->post(route('register.store'), datosDeRegistro(['terminos' => '1']));

    $usuario = User::query()->where('email', 'ana@example.com')->firstOrFail();

    expect($usuario->terminos_version)->toBe(config('legales.version'))
        ->and($usuario->terminos_aceptados_en)->not->toBeNull()
        ->and($usuario->aceptoTerminosVigentes())->toBeTrue();
});

it('a una cuenta sin aceptar la manda a aceptar, y después vuelve a donde iba', function () {
    $usuario = User::factory()->sinAceptarTerminos()->create();

    $this->actingAs($usuario)
        ->get(route('gallery'))
        ->assertRedirect(route('terminos.aceptar'));

    $this->get(route('terminos.aceptar'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('terminos/Aceptar')
            ->where('primeraVez', true));

    $this->post(route('terminos.aceptar.store'), ['terminos' => '1'])
        ->assertRedirect(route('gallery'));

    expect($usuario->fresh()->aceptoTerminosVigentes())->toBeTrue();
});

it('aceptar exige marcar la casilla', function () {
    $usuario = User::factory()->sinAceptarTerminos()->create();

    $this->actingAs($usuario)
        ->post(route('terminos.aceptar.store'))
        ->assertSessionHasErrors('terminos');

    expect($usuario->fresh()->terminos_version)->toBeNull();
});

it('subir la versión vuelve a pedir la aceptación', function () {
    $usuario = User::factory()->create();
    $this->actingAs($usuario)->get(route('dashboard'))->assertOk();

    config()->set('legales.version', '2099-01-01');

    $this->get(route('dashboard'))->assertRedirect(route('terminos.aceptar'));
    $this->get(route('terminos.aceptar'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('primeraVez', false));
});

it('un pedido JSON sin aceptar recibe 409 y no pisa a dónde volver', function () {
    $usuario = User::factory()->sinAceptarTerminos()->create();
    $this->actingAs($usuario);

    $this->get(route('gallery'))->assertRedirect(route('terminos.aceptar'));
    // El indicador de la placa consulta en segundo plano.
    $this->getJson(route('gpu.status'))->assertStatus(409);

    $this->post(route('terminos.aceptar.store'), ['terminos' => '1'])
        ->assertRedirect(route('gallery'));
});

it('sin aceptar igual puede leer, llevarse sus datos e imágenes, y borrar la cuenta', function () {
    Storage::fake('local');
    $usuario = User::factory()->sinAceptarTerminos()->create();
    $generation = Generation::factory()->for($usuario)->status(GenerationStatus::Done)->create();
    $image = app(ImageStore::class)->store($generation, fakeComfyPng());
    $this->actingAs($usuario);

    $this->get(route('terminos'))->assertOk();
    $this->get(route('privacidad'))->assertOk();
    $this->get(route('exportacion.datos'))->assertOk();
    $this->get(route('images.download', [$image, 'png']))->assertOk();

    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');
    expect(User::query()->find($usuario->id))->toBeNull();
});

it('las exentas con sesión son exactamente las que se listan', function () {
    $exentasConSesion = collect(Route::getRoutes())
        ->filter(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => $m === 'auth' || str_starts_with($m, 'auth:')))
        ->map(fn ($r) => $r->getName())
        ->filter(fn ($nombre) => $nombre !== null && Str::is(AceptoTerminos::EXENTAS, $nombre))
        ->sort()
        ->values()
        ->all();

    expect($exentasConSesion)->toBe([
        'exportacion.datos',
        'images.download',
        'logout',
        'profile.destroy',
        'terminos.aceptar',
        'terminos.aceptar.store',
        'verification.notice',
        'verification.send',
        'verification.verify',
    ]);
});

it('los textos se abren con sesión y sin ella, con el contacto completado', function (string $ruta) {
    $this->get(route($ruta))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Legal')
            ->where('auth.user', null)
            ->where('html', fn ($html) => str_contains($html, (string) config('legales.contacto'))
                && ! str_contains($html, '{contacto}')));

    $this->actingAs(User::factory()->create())
        ->get(route($ruta))
        ->assertOk();
})->with(['terminos', 'privacidad']);

it('descargar mis datos baja la cuenta y todos los pedidos, y nada de otros', function () {
    Storage::fake('local');
    $usuario = User::factory()->create(['name' => 'Ana']);
    $generation = Generation::factory()->for($usuario)->status(GenerationStatus::Done)->create(['prompt' => 'un faro']);
    app(ImageStore::class)->store($generation, fakeComfyPng());
    Generation::factory()->for($usuario)->status(GenerationStatus::Failed)->create(['prompt' => 'falló']);
    Generation::factory()->create(['prompt' => 'de otro']);

    $response = $this->actingAs($usuario)->get(route('exportacion.datos'));

    $response->assertOk()->assertDownload('lumora-mis-datos-'.now()->format('Y-m-d').'.json');
    $data = json_decode($response->streamedContent(), true);

    expect($data['cuenta']['nombre'])->toBe('Ana')
        ->and(collect($data['pedidos'])->pluck('prompt')->all())->toBe(['un faro', 'falló'])
        ->and($data['pedidos'][0]['imagen']['descargar_png'])->toContain('/descargar/png')
        ->and($data['pedidos'][1]['imagen'])->toBeNull();
});

it('eliminar la cuenta borra las imágenes del disco y las sesiones', function () {
    Storage::fake('local');
    $usuario = User::factory()->create();
    $generation = Generation::factory()->for($usuario)->status(GenerationStatus::Done)->create();
    $image = app(ImageStore::class)->store($generation, fakeComfyPng());
    $ajena = app(ImageStore::class)->store(Generation::factory()->status(GenerationStatus::Done)->create(), fakeComfyPng());
    DB::table('sessions')->insert(['id' => 'otra-sesion', 'user_id' => $usuario->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($usuario)->delete(route('profile.destroy'), ['password' => 'password']);

    Storage::disk('local')->assertMissing($image->path);
    Storage::disk('local')->assertMissing($image->thumb_path);
    Storage::disk('local')->assertExists($ajena->path);
    expect(Generation::query()->where('user_id', $usuario->id)->count())->toBe(0)
        ->and(Image::withTrashed()->where('user_id', $usuario->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $usuario->id)->count())->toBe(0);
});

it('la papelera se vacía a los días que promete la política, aunque no haya retención', function () {
    Storage::fake('local');
    $generation = Generation::factory()->status(GenerationStatus::Done)->create();
    $vieja = app(ImageStore::class)->store($generation, fakeComfyPng());
    $nueva = app(ImageStore::class)->store(Generation::factory()->status(GenerationStatus::Done)->create(), fakeComfyPng());
    $vieja->delete();
    $nueva->delete();
    $vieja->forceFill(['deleted_at' => now()->subDays(Image::TRASH_DAYS + 1)])->save();
    $nueva->forceFill(['deleted_at' => now()->subDays(Image::TRASH_DAYS - 1)])->save();

    $this->artisan('images:prune')->assertSuccessful();

    expect(Image::withTrashed()->find($vieja->id))->toBeNull()
        ->and(Image::withTrashed()->find($nueva->id))->not->toBeNull();
    Storage::disk('local')->assertMissing($vieja->path);

    $politica = (string) file_get_contents(resource_path('legales/privacidad.md'));
    expect($politica)->toContain('**'.Image::TRASH_DAYS.' días**');
});

it('Perfil ofrece descargar los datos, que es lo que promete la política', function () {
    $perfil = (string) file_get_contents(resource_path('js/pages/settings/Profile.vue'));

    expect($perfil)->toContain('misDatos().url')
        ->and((string) file_get_contents(resource_path('legales/privacidad.md')))
        ->toContain('Descargar mis datos');
});

it('el service worker no guarda imágenes privadas ni respuestas de Inertia', function () {
    $sw = (string) file_get_contents(public_path('sw.js'));

    expect($sw)->toContain("request.headers.has('X-Inertia')")
        ->toContain("url.pathname.startsWith('/imagenes/')");
});
