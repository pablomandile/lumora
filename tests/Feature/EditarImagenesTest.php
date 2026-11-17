<?php

use App\Enums\GenerationStatus;
use App\Jobs\GenerateImage;
use App\Models\AiModel;
use App\Models\Attachment;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\GenerationService;
use App\Services\Images\AttachmentStore;
use App\Services\Images\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeGateway;

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    // Arranca solo para el admin; en estos tests lo usa cualquiera.
    AiModel::query()->where('key', 'qwen-image-edit')->update(['admin_only' => false]);
});

/** Un JPEG con un bloque EXIF (como el de una foto de celular con GPS). */
function jpegWithExif(int $width = 1200, int $height = 1500): UploadedFile
{
    $img = imagecreatetruecolor($width, $height);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 40));
    ob_start();
    imagejpeg($img, null, 90);
    $jpg = (string) ob_get_clean();

    // TIFF mínimo válido (little endian, un IFD vacío) dentro de un APP1 "Exif".
    $tiff = "II*\x00\x08\x00\x00\x00\x00\x00\x00\x00\x00\x00";
    $payload = "Exif\x00\x00".$tiff.'GPS -34.6037,-58.3816';
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    $bytes = substr($jpg, 0, 2).$app1.substr($jpg, 2);

    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'foto.jpg', 'image/jpeg', null, true);
}

function generationWithImage(User $user): Generation
{
    $generation = Generation::factory()->for($user)->status(GenerationStatus::Done)->create([
        'conversation_id' => $user->conversations()->create(['title' => 'Prueba'])->id,
    ]);
    app(ImageStore::class)->store($generation, fakeComfyPng());

    return $generation->fresh(['image']);
}

it('edita con una foto subida: elige el modelo de edición y toma la proporción de la foto', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), [
            'prompt' => 'poné un sombrero',
            'images' => [UploadedFile::fake()->image('cara.jpg', 1080, 1350)],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $generation = $user->generations()->sole();
    [$width, $height] = GenerationService::editSize(1080, 1350);

    expect($generation->model)->toBe('qwen-image-edit')
        ->and($generation->steps)->toBe(4)
        ->and($generation->aspect)->toBeNull()
        ->and([$generation->width, $generation->height])->toBe([$width, $height])
        ->and($generation->attachments)->toHaveCount(1);

    $attachment = $generation->attachments->first();
    expect($attachment->path)->toStartWith("attachments/{$user->id}/")
        ->and($attachment->width)->toBe(1080);
    Storage::disk('local')->assertExists([$attachment->path, $attachment->thumb_path]);

    Queue::assertPushed(GenerateImage::class);
});

it('calcula un tamaño de ~1 MP con la proporción de la imagen, en múltiplos de 16', function (int $w, int $h) {
    [$width, $height] = GenerationService::editSize($w, $h);

    expect($width % 16)->toBe(0)
        ->and($height % 16)->toBe(0)
        ->and($width * $height)->toBeGreaterThan(900_000)->toBeLessThan(1_200_000)
        ->and(abs($width / $height - $w / $h))->toBeLessThan(0.02);
})->with([[1080, 1350], [4000, 3000], [640, 480], [1920, 1080], [1000, 1000]]);

it('reduce la foto a 2048 px y le borra el EXIF', function () {
    $user = User::factory()->create();
    $file = jpegWithExif(3000, 2000);
    expect(file_get_contents($file->getRealPath()))->toContain('GPS -34.6037');

    $attachment = app(AttachmentStore::class)->storeUpload($user, $file);
    $stored = (string) Storage::disk('local')->get($attachment->path);

    expect([$attachment->width, $attachment->height])->toBe([2048, 1365])
        ->and($stored)->not->toContain('Exif')
        ->and($stored)->not->toContain('GPS')
        ->and(substr($stored, 0, 3))->toBe("\xFF\xD8\xFF");
});

it('la misma foto subida dos veces es un solo adjunto', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('cara.jpg', 600, 600);
    $store = app(AttachmentStore::class);

    $a = $store->storeUpload($user, $file);
    $b = $store->storeUpload($user, $file);

    expect($b->id)->toBe($a->id)
        ->and($user->attachments()->count())->toBe(1);
});

it('"Editar esta imagen" copia una imagen propia como imagen 1, antes de las fotos nuevas', function () {
    $user = User::factory()->create();
    $image = generationWithImage($user)->image;

    $this->actingAs($user)
        ->post(route('generations.store'), [
            'prompt' => 'poné mi cara',
            'model' => 'qwen-image-edit',
            'image_ids' => [$image->id],
            'images' => [UploadedFile::fake()->image('cara.jpg', 500, 500)],
        ])
        ->assertSessionHasNoErrors();

    $generation = $user->generations()->where('model', 'qwen-image-edit')->sole();
    $attachments = $generation->attachments;

    expect($attachments)->toHaveCount(2)
        ->and($attachments[0]->getRelationValue('pivot')->position)->toBe(1)
        // La imagen de la galería (64×48 en los tests) va primero y define el tamaño.
        ->and([$attachments[0]->width, $attachments[0]->height])->toBe([64, 48])
        ->and([$attachments[1]->width, $attachments[1]->height])->toBe([500, 500])
        ->and([$generation->width, $generation->height])->toBe(GenerationService::editSize(64, 48));
});

it('otra variante reusa los adjuntos ya subidos', function () {
    $user = User::factory()->create();
    $attachment = app(AttachmentStore::class)->storeUpload($user, UploadedFile::fake()->image('a.jpg', 800, 600));

    $this->actingAs($user)
        ->post(route('generations.store'), [
            'prompt' => 'otra vez',
            'model' => 'qwen-image-edit',
            'attachment_ids' => [$attachment->id],
        ])
        ->assertSessionHasNoErrors();

    expect($user->generations()->sole()->attachments->modelKeys())->toBe([$attachment->id])
        ->and(Attachment::query()->count())->toBe(1);
});

it('rechaza pedidos de edición inválidos', function (Closure $data, string $field, string $message) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), ['prompt' => 'x', ...$data($user)])
        ->assertSessionHasErrors($field);

    expect(session('errors')->first($field))->toContain($message)
        ->and($user->generations()->count())->toBe(0);
})->with([
    'z-image no usa imágenes' => [
        fn () => ['model' => 'z-image-turbo', 'images' => [UploadedFile::fake()->image('a.jpg')]],
        'images', 'no usa imágenes',
    ],
    'editar sin imagen' => [fn () => ['model' => 'qwen-image-edit'], 'images', 'Adjuntá'],
    'más de 3 entre todas' => [
        fn (User $u) => [
            'attachment_ids' => Attachment::factory()->count(2)->for($u)->create()->modelKeys(),
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ],
        'images', 'hasta 3',
    ],
    'adjunto de otro usuario' => [
        fn () => ['attachment_ids' => [Attachment::factory()->create()->id]],
        'attachment_ids.0', 'ya no está disponible',
    ],
    'imagen de otro usuario' => [
        fn () => ['image_ids' => [generationWithImage(User::factory()->create())->image->id]],
        'image_ids.0', 'ya no está disponible',
    ],
    'un archivo que no es imagen' => [
        fn () => ['images' => [UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')]],
        'images.0', 'no es una imagen',
    ],
]);

it('el modelo de edición, mientras es solo para el admin, no lo puede usar nadie más', function () {
    AiModel::query()->where('key', 'qwen-image-edit')->update(['admin_only' => true]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), ['prompt' => 'x', 'images' => [UploadedFile::fake()->image('a.jpg')]])
        ->assertSessionHasErrors(['model' => 'Editar imágenes todavía no está disponible.']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('generations.store'), ['prompt' => 'x', 'images' => [UploadedFile::fake()->image('a.jpg')]])
        ->assertSessionHasNoErrors();
});

it('el Job sube las imágenes en orden y manda sus nombres al gateway', function () {
    Sleep::fake();
    $gateway = FakeGateway::install();
    $user = User::factory()->create();
    $store = app(AttachmentStore::class);
    $flyer = $store->storeUpload($user, UploadedFile::fake()->image('flyer.png', 800, 1000));
    $face = $store->storeUpload($user, UploadedFile::fake()->image('cara.jpg', 600, 600));

    $generation = Generation::factory()->for($user)->create(['model' => 'qwen-image-edit', 'width' => 912, 'height' => 1152, 'steps' => 4]);
    $generation->attachments()->attach([$flyer->id => ['position' => 1], $face->id => ['position' => 2]]);

    app()->call([new GenerateImage($generation->id), 'handle']);

    $names = array_keys($gateway->inputs);
    expect($generation->refresh()->status)->toBe(GenerationStatus::Done)
        ->and($gateway->inputs)->toHaveCount(2)
        ->and($gateway->inputs[$names[0]])->toBe(Storage::disk('local')->get($flyer->path))
        ->and($gateway->submitted[0])->toMatchArray([
            'model' => 'qwen-image-edit',
            'width' => 912,
            'height' => 1152,
            'images' => $names,
        ])
        ->and($gateway->calls)->toContain('POST /inputs');
});

it('un pedido sin imágenes no manda "images" al gateway', function () {
    Sleep::fake();
    $gateway = FakeGateway::install();

    app()->call([new GenerateImage(Generation::factory()->create()->id), 'handle']);

    expect($gateway->submitted[0])->not->toHaveKey('images')
        ->and($gateway->calls)->not->toContain('POST /inputs');
});

it('si falta el archivo de una imagen de entrada, la generación falla con un mensaje claro', function () {
    Sleep::fake();
    FakeGateway::install();
    $generation = Generation::factory()->create(['model' => 'qwen-image-edit']);
    $generation->attachments()->attach(Attachment::factory()->for($generation->user)->create()->id, ['position' => 1]);

    app()->call([new GenerateImage($generation->id), 'handle']);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error)->toContain('ya no está disponible');
});

it('las imágenes de entrada son privadas', function () {
    $user = User::factory()->create();
    $attachment = app(AttachmentStore::class)->storeUpload($user, UploadedFile::fake()->image('cara.jpg', 300, 300));

    $this->actingAs($user)->get(route('attachments.thumb', $attachment))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
    $this->actingAs($user)->get(route('attachments.show', $attachment))->assertOk();

    $this->actingAs(User::factory()->create())->get(route('attachments.show', $attachment))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('attachments.show', $attachment))->assertOk();
});

it('el hilo muestra las imágenes de entrada de cada pedido, en orden', function () {
    $user = User::factory()->create();
    $generation = generationWithImage($user);
    $attachments = Attachment::factory()->count(2)->for($user)->create();
    $generation->attachments()->attach([
        $attachments[1]->id => ['position' => 1],
        $attachments[0]->id => ['position' => 2],
    ]);

    $this->actingAs($user)
        ->get(route('conversations.show', $generation->conversation_id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.attachments.0.id', $attachments[1]->id)
            ->where('generations.0.attachments.1.id', $attachments[0]->id)
            ->where('generations.0.image.attachments.0.id', $attachments[1]->id)
            ->where('generations.0.image.attachments.1.thumb_url', route('attachments.thumb', $attachments[0]))
            ->where('models.1.key', 'qwen-image-edit')
            ->where('models.1.max_images', 3));
});

it('precarga "Editar esta imagen" solo con una imagen propia', function () {
    $user = User::factory()->create();
    $mine = generationWithImage($user)->image;
    $other = generationWithImage(User::factory()->create())->image;

    $this->actingAs($user)
        ->get(route('dashboard', ['editar' => $mine->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prefill.edit_image.id', $mine->id)
            ->where('prefill.edit_image.thumb_url', route('images.thumb', $mine)));

    $this->actingAs($user)
        ->get(route('dashboard', ['editar' => $other->id]))
        ->assertInertia(fn (Assert $page) => $page->where('prefill.edit_image', null));
});

it('"Reusar prompt" de una edición precarga el modelo y solo los adjuntos propios, en orden', function () {
    $user = User::factory()->create();
    [$a, $b] = Attachment::factory()->count(2)->for($user)->create();
    $ajeno = Attachment::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['modelo' => 'qwen-image-edit', 'adjuntos' => "{$b->id},{$ajeno->id},{$a->id}"]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prefill.model', 'qwen-image-edit')
            ->where('prefill.attachments', [
                ['id' => $b->id, 'thumb_url' => route('attachments.thumb', $b->id)],
                ['id' => $a->id, 'thumb_url' => route('attachments.thumb', $a->id)],
            ]));
});

it('attachments:prune borra solo las que ya no usa ningún pedido', function () {
    $user = User::factory()->create();
    $old = fn () => Attachment::factory()->for($user)->create(['created_at' => now()->subDays(2)]);

    $orphan = $old();
    $recentOrphan = Attachment::factory()->for($user)->create();

    $pending = $old();
    Generation::factory()->for($user)->create()->attachments()->attach($pending->id, ['position' => 1]);

    $failed = $old();
    Generation::factory()->for($user)->status(GenerationStatus::Failed)->create()->attachments()->attach($failed->id, ['position' => 1]);

    $inGallery = $old();
    generationWithImage($user)->attachments()->attach($inGallery->id, ['position' => 1]);

    $inTrash = $old();
    $trashed = generationWithImage($user);
    $trashed->attachments()->attach($inTrash->id, ['position' => 1]);
    $trashed->image->delete();

    $imageGone = $old();
    $gone = generationWithImage($user);
    $gone->attachments()->attach($imageGone->id, ['position' => 1]);
    $gone->image->forceDelete();

    Storage::disk('local')->put($orphan->path, 'x');

    Artisan::call('attachments:prune');

    expect(Attachment::query()->pluck('id')->all())->toEqualCanonicalizing([
        $recentOrphan->id, $pending->id, $failed->id, $inGallery->id, $inTrash->id,
    ]);
    Storage::disk('local')->assertMissing($orphan->path);
});

it('eliminar la cuenta borra las fotos subidas', function () {
    $user = User::factory()->create();
    $attachment = app(AttachmentStore::class)->storeUpload($user, UploadedFile::fake()->image('cara.jpg', 300, 300));

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);

    expect(Attachment::query()->find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing($attachment->path);
});

it('la exportación de datos incluye las imágenes de entrada', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create(['model' => 'qwen-image-edit']);
    $attachment = Attachment::factory()->for($user)->create();
    $generation->attachments()->attach($attachment->id, ['position' => 1]);

    $json = json_decode($this->actingAs($user)->get(route('exportacion.datos'))->streamedContent(), true);

    expect($json['pedidos'][0]['imagenes_de_entrada'])->toBe([
        ['posicion' => 1, 'descargar' => route('attachments.show', $attachment)],
    ]);
});
