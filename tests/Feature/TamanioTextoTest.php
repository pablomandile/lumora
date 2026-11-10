<?php

declare(strict_types=1);

use App\Enums\TamanioTexto;
use App\Models\User;

it('viene en Normal por defecto, no en el tamaño chico', function (): void {
    expect(TamanioTexto::porDefecto())->toBe(TamanioTexto::Normal)
        ->and(User::factory()->create()->tamanio_texto)->toBe(TamanioTexto::Normal);
});

/*
 * En porcentaje y no en px: un tamaño en px pisa el que la persona ya configuró
 * en su navegador, que es justo lo que alguien con poca vista suele haber
 * tocado. Si esto cambia a px, se rompe esa composición sin que se note.
 */
it('expresa los tamaños en porcentaje', function (): void {
    foreach (TamanioTexto::cases() as $caso) {
        expect($caso->porcentaje())->toEndWith('%');
    }

    expect(TamanioTexto::Normal->porcentaje())->toBe('100%');
});

it('escribe el atributo en el html, sin depender de JavaScript', function (): void {
    $html = $this->get('/login')->getContent();

    expect($html)->toContain('data-texto="normal"');
});

it('un invitado puede agrandar la letra y se recuerda por la cookie', function (): void {
    $respuesta = $this->put(route('tamanio-texto.update'), [
        'tamanio_texto' => TamanioTexto::Grande->value,
    ]);

    $respuesta->assertRedirect()
        ->assertCookie('tamanio_texto', TamanioTexto::Grande->value, encrypted: false);

    expect($this->withUnencryptedCookie('tamanio_texto', 'grande')->get('/login')->getContent())
        ->toContain('data-texto="grande"');
});

it('a quien tiene sesión se lo guarda en la cuenta', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->put(route('tamanio-texto.update'), ['tamanio_texto' => 'pequeno'])
        ->assertRedirect();

    expect($usuario->refresh()->tamanio_texto)->toBe(TamanioTexto::Pequeno);
});

/*
 * El caso que motiva la columna: la persona entra desde un teléfono nuevo, sin
 * la cookie. Si mandara la cookie, tendría que volver a agrandar la letra en
 * cada dispositivo.
 */
it('manda la cuenta por encima de la cookie', function (): void {
    $usuario = User::factory()->create(['tamanio_texto' => TamanioTexto::Grande]);

    $html = $this->actingAs($usuario)
        ->withUnencryptedCookie('tamanio_texto', 'pequeno')
        ->get('/estudio')
        ->getContent();

    expect($html)->toContain('data-texto="grande"');
});

it('rechaza un tamaño que no existe', function (): void {
    $this->put(route('tamanio-texto.update'), ['tamanio_texto' => 'gigante'])
        ->assertSessionHasErrors('tamanio_texto');
});

/*
 * La cookie la escribe cualquiera; un valor raro tiene que caer en el default y
 * no dejar la página sin tamaño.
 */
it('una cookie con basura cae en el tamaño por defecto', function (): void {
    expect($this->withUnencryptedCookie('tamanio_texto', 'jajaja')->get('/login')->getContent())
        ->toContain('data-texto="normal"');
});

it('avisa con un toast', function (): void {
    $this->actingAs(User::factory()->create())
        ->put(route('tamanio-texto.update'), ['tamanio_texto' => 'pequeno'])
        ->assertInertiaFlash('toast.message', 'Listo, cambiamos el tamaño de la letra.');
});

it('la pantalla de Apariencia ofrece los tres tamaños con su muestra', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('settings/Appearance')
            ->where('tamanioTexto', 'normal')
            ->has('tamaniosTexto', 3)
            // La escala es lo que permite dibujar cada opción a su tamaño real.
            ->has('tamaniosTexto.0', fn ($opcion) => $opcion
                ->where('valor', 'pequeno')
                ->where('escala', 87.5)
                ->etc()
            )
        );
});
