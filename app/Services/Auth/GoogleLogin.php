<?php

namespace App\Services\Auth;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Exceptions\GoogleLoginRejected;
use App\Models\User;
use App\Notifications\NewUserRegistered;
use App\Support\GoogleAccount;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

/**
 * Resuelve una cuenta de Google en un usuario de Lumora.
 *
 * El email manda: Google ya lo verificó, así que si coincide con una cuenta
 * existente es la misma persona y se le vincula el google_id en vez de crear
 * otra.
 *
 * Una cuenta nueva sigue el mismo camino que el registro con contraseña: queda
 * pendiente hasta que el admin la apruebe, y sin términos aceptados, así que el
 * middleware AceptoTerminos la manda a aceptarlos en el primer pedido.
 */
class GoogleLogin
{
    /**
     * Sin credenciales, el botón no se muestra y las rutas dan 404: el código
     * puede estar desplegado antes de que exista el cliente en Google Cloud.
     */
    public static function enabled(): bool
    {
        // Config::get y no Config::string: sin las variables el valor es null.
        return filled(Config::get('services.google.client_id'))
            && filled(Config::get('services.google.client_secret'));
    }

    /**
     * @throws GoogleLoginRejected
     */
    public function resolve(GoogleAccount $account): User
    {
        if (blank($account->id) || blank($account->email)) {
            throw new GoogleLoginRejected('Google no devolvió el email de la cuenta.');
        }

        if (! $account->emailVerified) {
            throw new GoogleLoginRejected('Esa cuenta de Google no tiene el email verificado.');
        }

        $nueva = false;

        $user = DB::transaction(function () use ($account, &$nueva) {
            // 1. Ya entró antes con esta cuenta de Google. Se reconoce por el
            //    id y no por el email, que en Google se puede cambiar.
            $user = User::query()->where('google_id', $account->id)->first();

            if ($user) {
                return $this->verify($user);
            }

            // 2. Existe por email (se registró con contraseña): se vinculan.
            $user = User::query()->where('email', $account->email)->first();

            if ($user) {
                $user->google_id = $account->id;

                return $this->verify($user);
            }

            // 3. Cuenta nueva: solo si el registro está abierto.
            if (! Features::enabled(Features::registration())) {
                throw new GoogleLoginRejected("La cuenta {$account->email} no está registrada en Lumora.");
            }

            $nueva = true;

            return $this->create($account);
        });

        // Fuera de la transacción: el mail al admin no tiene que frenar el alta.
        if ($nueva && ! $user->isAdmin()) {
            Notification::send(User::admins()->get(), new NewUserRegistered($user));
        }

        return $user;
    }

    private function verify(User $user): User
    {
        // Si se había registrado por email sin confirmarlo, Google lo confirmó.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()]);
        }
        $user->save();

        return $user;
    }

    private function create(GoogleAccount $account): User
    {
        $esAdmin = strcasecmp($account->email, (string) config('lumora.admin_email')) === 0;

        // forceFill: google_id, el rol y la aprobación no son fillable a propósito.
        $user = new User;
        $user->forceFill([
            'name' => $account->name ?: str($account->email)->before('@')->toString(),
            'email' => $account->email,
            'google_id' => $account->id,
            'password' => null,
            'email_verified_at' => now(),
            ...($esAdmin ? [
                'role' => UserRole::Admin,
                'approval_status' => ApprovalStatus::Approved,
                'approved_at' => now(),
            ] : []),
        ])->save();

        return $user;
    }
}
