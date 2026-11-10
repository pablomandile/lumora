<?php

use App\Enums\GenerationStatus;
use App\Jobs\GenerateImage;
use App\Models\Conversation;
use App\Models\Generation;
use App\Models\User;
use App\Services\Images\ImageStore;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
});

function doneInConversation(Conversation $conversation, array $attributes = []): Generation
{
    $generation = Generation::factory()
        ->for($conversation->user)
        ->status(GenerationStatus::Done)
        ->create(['conversation_id' => $conversation->id, 'seed' => 42, ...$attributes]);
    app(ImageStore::class)->store($generation, fakeComfyPng());

    return $generation->fresh(['image']);
}

it('el estudio sin conversación es una nueva, vacía', function () {
    $user = User::factory()->create(['daily_quota' => 5]);
    Generation::factory()->for($user)->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio')
            ->where('conversation', null)
            ->has('generations', 0)
            ->where('quota', ['limit' => 5, 'used' => 1, 'remaining' => 4])
            ->has('aspects', 8)
            ->where('models.0.key', 'z-image-turbo'));
});

it('muestra una conversación como hilo, del pedido más viejo al más nuevo', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create(['title' => 'Faros']);
    $first = doneInConversation($conversation, ['created_at' => now()->subMinute()]);
    $second = Generation::factory()->for($user)->create(['conversation_id' => $conversation->id]);
    Generation::factory()->for($user)->create(); // sin conversación
    Generation::factory()->for(User::factory())->create(); // de otro usuario

    $this->actingAs($user)->get(route('conversations.show', $conversation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio')
            ->where('conversation', ['id' => $conversation->id, 'title' => 'Faros'])
            ->has('generations', 2)
            ->where('generations.0.id', $first->id)
            ->where('generations.0.image.id', $first->image->id)
            ->where('generations.0.image.conversation_id', $conversation->id)
            ->where('generations.0.image.seed', 42)
            ->where('generations.1.id', $second->id));
});

it('no muestra las terminadas cuya imagen se borró', function () {
    $conversation = Conversation::factory()->create();
    doneInConversation($conversation)->image->delete();

    $this->actingAs($conversation->user)->get(route('conversations.show', $conversation))
        ->assertInertia(fn (Assert $page) => $page->has('generations', 0));
});

it('no deja ver la conversación de otro, ni al admin', function () {
    $conversation = Conversation::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('conversations.show', $conversation))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('conversations.show', $conversation))->assertForbidden();
});

it('el primer pedido abre una conversación titulada con el prompt', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('generations.store'), ['prompt' => 'un gato astronauta', 'aspect' => '9:16', 'seed' => 123]);

    $conversation = $user->conversations()->sole();
    $response->assertRedirect(route('conversations.show', $conversation));

    $generation = $user->generations()->sole();
    expect($conversation->title)->toBe('Un gato astronauta')
        ->and($generation->conversation_id)->toBe($conversation->id)
        ->and($generation->prompt)->toBe('un gato astronauta')
        ->and([$generation->width, $generation->height])->toBe([768, 1344])
        ->and($generation->seed)->toBe(123);
    Queue::assertPushed(GenerateImage::class);
});

it('los pedidos siguientes se suman a la conversación y la suben en el menú', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create(['updated_at' => now()->subDay()]);
    $recent = Conversation::factory()->for($user)->create(['updated_at' => now()->subHour()]);

    $this->actingAs($user)
        ->post(route('generations.store'), ['prompt' => 'otra más', 'conversation_id' => $conversation->id])
        ->assertRedirect(route('conversations.show', $conversation));

    expect($conversation->generations()->count())->toBe(1)
        ->and($user->conversations()->count())->toBe(2);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('conversations', 2)
            ->where('conversations.0.id', $conversation->id)
            ->where('conversations.1.id', $recent->id));
});

it('el menú no muestra conversaciones ajenas', function () {
    $user = User::factory()->create();
    Conversation::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('conversations', 0));
});

it('no deja pedir en la conversación de otro', function () {
    $other = Conversation::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), ['prompt' => 'x', 'conversation_id' => $other->id])
        ->assertSessionHasErrors('conversation_id');

    expect($other->generations()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('sin cuota vuelve a la conversación con un aviso', function () {
    $user = User::factory()->create(['daily_quota' => 1]);
    $conversation = Conversation::factory()->for($user)->create();
    Generation::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('generations.store'), ['prompt' => 'otro', 'conversation_id' => $conversation->id])
        ->assertRedirect(route('conversations.show', $conversation))
        ->assertInertiaFlash('toast.type', 'error');

    expect($conversation->generations()->count())->toBe(0);
});

it('renombra y elimina conversaciones propias; las imágenes quedan en la galería', function () {
    $conversation = Conversation::factory()->create(['title' => 'Viejo']);
    $user = $conversation->user;
    $generation = doneInConversation($conversation);
    $intruder = User::factory()->admin()->create();

    $this->actingAs($intruder)->patch(route('conversations.update', $conversation), ['title' => 'Mío'])->assertForbidden();
    $this->actingAs($intruder)->delete(route('conversations.destroy', $conversation))->assertForbidden();

    $this->actingAs($user)->patch(route('conversations.update', $conversation), ['title' => ''])
        ->assertSessionHasErrors('title');
    $this->actingAs($user)->patch(route('conversations.update', $conversation), ['title' => '  Faros  '])
        ->assertRedirect();
    expect($conversation->fresh()->title)->toBe('Faros');

    // Borrar la que está abierta vuelve a una conversación nueva.
    $this->actingAs($user)
        ->delete(route('conversations.destroy', $conversation), ['current' => $conversation->id])
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'success');

    expect(Conversation::query()->find($conversation->id))->toBeNull()
        ->and($generation->fresh()->conversation_id)->toBeNull();

    $this->actingAs($user)->get(route('gallery'))
        ->assertInertia(fn (Assert $page) => $page->has('images.data', 1));
});

it('arma el título con el comienzo del prompt', function (string $prompt, string $title) {
    expect(Conversation::titleFromPrompt($prompt))->toBe($title);
})->with([
    'corto' => ['un faro', 'Un faro'],
    'largo' => ['un faro en un acantilado al atardecer con olas rompiendo y gaviotas', 'Un faro en un acantilado al atardecer con…'],
    'saltos de línea' => ["  dos\n\nlíneas  ", 'Dos líneas'],
    'palabra enorme' => [str_repeat('a', 80), 'A'.str_repeat('a', 58).'…'],
    'vacío' => ['   ', 'Conversación nueva'],
]);
