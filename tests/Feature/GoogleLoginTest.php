<?php

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\NewUserRegistered;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * Ingreso con Google: una cuenta nueva sigue el mismo camino que el registro
 * con contraseña (pendiente de aprobación, términos por aceptar), salvo el
 * email del admin, que entra aprobado.
 */

beforeEach(function () {
    Notification::fake();
    config([
        'services.google.client_id' => 'id-de-prueba',
        'services.google.client_secret' => 'secreto-de-prueba',
    ]);
});

function googleDevuelve(string $email, string $id = 'google-123', bool $verificado = true): void
{
    $user = (new SocialiteUser)
        ->setRaw(['email_verified' => $verificado])
        ->map(['id' => $id, 'email' => $email, 'name' => 'Ana Pérez']);

    Socialite::shouldReceive('driver->user')->andReturn($user);
}

it('sin credenciales no muestra el botón y las rutas dan 404', function () {
    config(['services.google.client_id' => null]);

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('googleLoginEnabled', false));
    $this->get(route('google.redirect'))->assertNotFound();
    $this->get(route('google.callback'))->assertNotFound();
});

it('el botón manda a Google', function () {
    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('googleLoginEnabled', true));

    $this->get(route('google.redirect'))->assertRedirectContains('accounts.google.com');
});

it('una cuenta nueva queda pendiente, sin contraseña, y le avisa al admin', function () {
    $admin = User::factory()->admin()->create();
    googleDevuelve('Ana@Example.com');

    $this->get(route('google.callback'))->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->google_id)->toBe('google-123')
        ->and($user->name)->toBe('Ana Pérez')
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->approval_status)->toBe(ApprovalStatus::Pending)
        ->and($user->role)->toBe(UserRole::User)
        ->and($user->aceptoTerminosVigentes())->toBeFalse();
    Notification::assertSentTo($admin, NewUserRegistered::class);

    // Antes que nada, los términos.
    $this->get(route('dashboard'))->assertRedirect(route('terminos.aceptar'));
});

it('el email del admin entra aprobado y como admin', function () {
    googleDevuelve((string) config('lumora.admin_email'));

    $this->get(route('google.callback'));

    $user = User::query()->where('email', config('lumora.admin_email'))->firstOrFail();
    expect($user->isAdmin())->toBeTrue()
        ->and($user->isApproved())->toBeTrue();
    Notification::assertNothingSent();
});

it('una cuenta existente se vincula por email y conserva su contraseña y su rol', function () {
    $user = User::factory()->admin()->unverified()->create(['email' => 'ana@example.com']);
    $hash = $user->password;
    googleDevuelve('ana@example.com');

    $this->get(route('google.callback'))->assertRedirect(route('dashboard'));

    $user->refresh();
    $this->assertAuthenticatedAs($user);
    expect($user->google_id)->toBe('google-123')
        ->and($user->password)->toBe($hash)
        ->and($user->isAdmin())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
    expect(User::query()->count())->toBe(1);
});

it('la segunda vez la reconoce por el id aunque haya cambiado el email', function () {
    $user = User::factory()->create(['email' => 'vieja@example.com']);
    $user->forceFill(['google_id' => 'google-123'])->save();
    googleDevuelve('nueva@example.com');

    $this->get(route('google.callback'));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

it('rechaza un email sin verificar en Google', function () {
    googleDevuelve('ana@example.com', verificado: false);

    $this->get(route('google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('cancelar en Google vuelve al login sin error', function () {
    $this->get(route('google.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionMissing('error');
});

it('sin contraseña puede eliminar la cuenta y crear una contraseña', function () {
    $user = User::factory()->create(['password' => null]);
    $this->actingAs($user);

    $this->get(route('security.edit'))->assertOk();

    $this->put(route('user-password.update'), [
        'password' => 'una-clave-nueva-larga',
        'password_confirmation' => 'una-clave-nueva-larga',
    ])->assertSessionHasNoErrors();
    expect($user->fresh()->password)->not->toBeNull();

    $sinClave = User::factory()->create(['password' => null]);
    $this->actingAs($sinClave)->delete(route('profile.destroy'))->assertRedirect('/');
    expect(User::query()->find($sinClave->id))->toBeNull();
});
