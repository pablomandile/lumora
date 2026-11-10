<?php

use App\Enums\GenerationStatus;
use App\Jobs\GenerateImage;
use App\Models\Generation;
use App\Models\Image;
use App\Models\User;
use App\Services\Images\ImageStore;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
});

function doneGeneration(User $user, array $attributes = []): Generation
{
    $generation = Generation::factory()->for($user)->status(GenerationStatus::Done)->create(['seed' => 42, ...$attributes]);
    app(ImageStore::class)->store($generation, fakeComfyPng());

    return $generation->fresh(['image']);
}

it('muestra el estudio con las generaciones, la cuota y los aspectos', function () {
    $user = User::factory()->create(['daily_quota' => 5]);
    $done = doneGeneration($user);
    Generation::factory()->for($user)->create();
    Generation::factory()->for(User::factory())->create(); // de otro usuario

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio')
            ->has('generations', 2)
            ->where('generations.1.id', $done->id)
            ->where('generations.1.image.id', $done->image->id)
            ->where('generations.1.image.seed', 42)
            ->where('quota', ['limit' => 5, 'used' => 2, 'remaining' => 3])
            ->has('aspects', 8)
            ->where('models.0.key', 'z-image-turbo'));
});

it('no muestra las terminadas cuya imagen se borró', function () {
    $user = User::factory()->create();
    doneGeneration($user)->image->delete();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('generations', 0));
});

it('carga el prompt que llega por la URL', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard', ['prompt' => 'un faro', 'aspect' => '16:9', 'seed' => '7']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prefill', ['prompt' => 'un faro', 'aspect' => '16:9', 'seed' => 7]));
});

it('pide una imagen y la encola', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), ['prompt' => 'un gato astronauta', 'aspect' => '9:16', 'seed' => 123])
        ->assertRedirect(route('dashboard'));

    $generation = $user->generations()->sole();
    expect($generation->prompt)->toBe('un gato astronauta')
        ->and([$generation->width, $generation->height])->toBe([768, 1344])
        ->and($generation->seed)->toBe(123);
    Queue::assertPushed(GenerateImage::class);
});

it('valida el prompt y la seed', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), ['prompt' => '   ', 'seed' => -1, 'aspect' => '5:1'])
        ->assertSessionHasErrors(['prompt', 'seed', 'aspect']);

    Queue::assertNothingPushed();
});

it('avisa con un toast cuando se terminó la cuota', function () {
    $user = User::factory()->create(['daily_quota' => 1]);
    Generation::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('generations.store'), ['prompt' => 'otro'])
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'error');

    expect($user->generations()->count())->toBe(1);
});

it('una cuenta pendiente no puede generar', function () {
    $this->actingAs(User::factory()->pending()->create())
        ->post(route('generations.store'), ['prompt' => 'x'])
        ->assertRedirect(route('account.pending'));

    Queue::assertNothingPushed();
});

it('descarta solo generaciones propias que fallaron', function () {
    $user = User::factory()->create();
    $failed = Generation::factory()->for($user)->status(GenerationStatus::Failed)->create();
    $queued = Generation::factory()->for($user)->create();
    $other = Generation::factory()->status(GenerationStatus::Failed)->create();

    $this->actingAs($user)->delete(route('generations.destroy', $failed))->assertRedirect();
    $this->actingAs($user)->delete(route('generations.destroy', $queued))->assertStatus(409);
    $this->actingAs($user)->delete(route('generations.destroy', $other))->assertForbidden();

    expect(Generation::query()->pluck('id')->all())->not->toContain($failed->id)->toHaveCount(2);
});

it('frena los pedidos repetidos', function () {
    $user = User::factory()->admin()->create();

    foreach (range(1, 12) as $i) {
        $this->actingAs($user)->post(route('generations.store'), ['prompt' => "p{$i}"]);
    }

    $this->actingAs($user)->post(route('generations.store'), ['prompt' => 'uno más'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'warning');

    expect($user->generations()->count())->toBe(12);
});

it('la galería lista solo las imágenes propias, con búsqueda y favoritas', function () {
    $user = User::factory()->create();
    $cat = doneGeneration($user, ['prompt' => 'un gato naranja']);
    doneGeneration($user, ['prompt' => 'un perro']);
    doneGeneration(User::factory()->create(), ['prompt' => 'un gato ajeno']);
    $cat->image->forceFill(['favorite' => true])->save();

    $this->actingAs($user)->get(route('gallery'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Gallery')
            ->has('images.data', 2)
            ->where('total', 2));

    $this->actingAs($user)->get(route('gallery', ['q' => 'gato']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('images.data', 1)
            ->where('images.data.0.prompt', 'un gato naranja'));

    $this->actingAs($user)->get(route('gallery', ['favoritas' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('images.data', 1)
            ->where('images.data.0.id', $cat->image->id));

    // Los comodines de LIKE se buscan literalmente.
    $this->actingAs($user)->get(route('gallery', ['q' => '%']))
        ->assertInertia(fn (Assert $page) => $page->has('images.data', 0));
});

it('sirve la imagen y la miniatura solo al dueño y al admin', function () {
    $owner = User::factory()->create();
    $image = doneGeneration($owner)->image;

    $this->actingAs($owner)->get(route('images.show', $image))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
    $this->actingAs($owner)->get(route('images.thumb', $image))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');

    $this->actingAs(User::factory()->create())->get(route('images.show', $image))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('images.download', [$image, 'png']))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('images.show', $image))->assertOk();
    auth()->logout();
    $this->get(route('images.show', $image))->assertRedirect(route('login'));
});

it('descarga en PNG, JPG y WebP', function (string $format, string $mime, string $magic) {
    $user = User::factory()->create();
    $image = doneGeneration($user, ['prompt' => 'Un faro al atardecer'])->image;

    $response = $this->actingAs($user)->get(route('images.download', [$image, $format]));

    $response->assertOk()->assertHeader('Content-Type', $mime);
    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain("lumora-un-faro-al-atardecer-42.{$format}")
        ->and(str_contains(substr($format === 'png' ? $response->streamedContent() : (string) $response->getContent(), 0, 16), $magic))->toBeTrue();
})->with([
    'png' => ['png', 'image/png', 'PNG'],
    'jpg' => ['jpg', 'image/jpeg', "\xFF\xD8\xFF"],
    'webp' => ['webp', 'image/webp', 'WEBP'],
]);

it('rechaza formatos de descarga desconocidos', function () {
    $user = User::factory()->create();
    $image = doneGeneration($user)->image;

    $this->actingAs($user)->get("/imagenes/{$image->id}/descargar/gif")->assertNotFound();
});

it('marca favorita y borra solo imágenes propias', function () {
    $owner = User::factory()->create();
    $image = doneGeneration($owner)->image;
    $intruder = User::factory()->admin()->create();

    $this->actingAs($intruder)->patch(route('images.favorite', $image), ['favorite' => true])->assertForbidden();
    $this->actingAs($intruder)->delete(route('images.destroy', $image))->assertForbidden();

    $this->actingAs($owner)->patch(route('images.favorite', $image), ['favorite' => true])->assertRedirect();
    expect($image->fresh()->favorite)->toBeTrue();

    $this->actingAs($owner)->delete(route('images.destroy', $image))->assertRedirect();
    expect(Image::query()->find($image->id))->toBeNull()
        ->and(Image::withTrashed()->find($image->id))->not->toBeNull();
    Storage::disk('local')->assertExists($image->path);

    // Borrada, ya no se sirve.
    $this->actingAs($owner)->get(route('images.show', $image))->assertNotFound();
});
