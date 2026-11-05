<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('lumora:make-admin {email}', function (string $email) {
    $user = User::where('email', $email)->first();

    if (! $user) {
        $this->error("No existe un usuario con el email {$email}.");

        return 1;
    }

    $user->forceFill(['role' => UserRole::Admin])->save();
    $user->approve();
    $this->info("{$user->name} ahora es administrador.");

    return 0;
})->purpose('Convierte en administrador (aprobado) a un usuario existente');
