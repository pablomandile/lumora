<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El modelo de edición se mostraba como «Editar imágenes»: en el visor y en las
 * estadísticas tiene que verse qué modelo es. Solo cambia el nombre original,
 * por si ya lo renombraron a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_models')
            ->where('key', 'qwen-image-edit')
            ->where('label', 'Editar imágenes')
            ->update(['label' => 'Qwen Image Edit', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('ai_models')
            ->where('key', 'qwen-image-edit')
            ->where('label', 'Qwen Image Edit')
            ->update(['label' => 'Editar imágenes', 'updated_at' => now()]);
    }
};
