<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            // Clave que entiende el gateway (gpu_worker/lumora_gateway/workflows.py).
            $table->string('key', 64)->unique();
            $table->string('label', 100);
            $table->string('description')->nullable();
            // Pasos por defecto, máximos, etc.
            $table->json('defaults')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('admin_only')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        DB::table('ai_models')->insert([
            'key' => 'z-image-turbo',
            'label' => 'Z-Image Turbo',
            'description' => 'Rápido y fotorrealista. Escribe bien texto en inglés.',
            'defaults' => json_encode(['steps' => 9, 'max_steps' => 20]),
            'enabled' => true,
            'admin_only' => false,
            'sort' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
