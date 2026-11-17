<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Imágenes que sube el usuario para editar (o que toma de su galería).
        Schema::create('attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Rutas dentro del disco "local" (storage/app/private).
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('bytes');
            // Del archivo original: subir dos veces la misma foto reusa el adjunto.
            $table->char('sha256', 64);
            $table->timestamps();

            $table->index(['user_id', 'sha256']);
        });

        // Qué imágenes usó cada pedido y en qué orden (la 1 es la que se edita).
        Schema::create('attachment_generation', function (Blueprint $table) {
            $table->foreignUlid('generation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('attachment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');

            $table->primary(['generation_id', 'position']);
            $table->index('attachment_id');
        });

        DB::table('ai_models')->insert([
            'key' => 'qwen-image-edit',
            'label' => 'Editar imágenes',
            'description' => 'Editá o combiná hasta 3 imágenes: poné una cara en un flyer, cambiá un texto o el fondo.',
            // Medido en la RTX 3060: ~70 s con 1 imagen, ~90 s con 2 (docs/etapa2/resultados.md).
            'defaults' => json_encode(['steps' => 4, 'max_steps' => 8, 'min_images' => 1, 'max_images' => 3]),
            'enabled' => true,
            // Primero solo para el admin; se abre desde la base cuando esté probado en producción.
            'admin_only' => true,
            'sort' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ai_models')->where('key', 'qwen-image-edit')->delete();
        Schema::dropIfExists('attachment_generation');
        Schema::dropIfExists('attachments');
    }
};
