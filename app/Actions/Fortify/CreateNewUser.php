<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\NewUserRegistered;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'terminos' => ['accepted'],
        ], [
            'terminos.accepted' => 'Para crear la cuenta tenés que aceptar los términos y la política de privacidad.',
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
        $user->aceptarTerminos();

        // El email configurado como admin entra directo, sin esperar aprobación.
        if (strcasecmp($user->email, (string) config('lumora.admin_email')) === 0) {
            $user->forceFill([
                'role' => UserRole::Admin,
                'approval_status' => ApprovalStatus::Approved,
                'approved_at' => now(),
            ])->save();

            return $user;
        }

        Notification::send(User::admins()->get(), new NewUserRegistered($user));

        return $user;
    }
}
