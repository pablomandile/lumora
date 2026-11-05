<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Datos de desarrollo: el admin y un par de usuarios para probar la aprobación.
     * En producción el admin se crea registrándose con LUMORA_ADMIN_EMAIL.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Pablo Mandile',
            'email' => config('lumora.admin_email'),
        ]);

        User::factory()->create(['name' => 'Usuaria Aprobada', 'email' => 'aprobada@example.com']);
        User::factory()->pending()->create(['name' => 'Usuario Pendiente', 'email' => 'pendiente@example.com']);
    }
}
