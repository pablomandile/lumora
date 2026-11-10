<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // El `sub` de la cuenta de Google: no cambia aunque cambie el email.
            // unique + nullable: MySQL admite muchos NULL en un índice único.
            $table->string('google_id')->nullable()->unique()->after('email');

            // Quien entra con Google nunca eligió una contraseña. Guardarle una
            // al azar la haría figurar como que puede entrar con email y clave.
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
            $table->string('password')->nullable(false)->change();
        });
    }
};
