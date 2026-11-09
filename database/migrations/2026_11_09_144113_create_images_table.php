<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('generation_id')->constrained()->cascadeOnDelete();
            // Copia del dueño para filtrar la galería sin join.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Rutas dentro del disco "local" (storage/app/private).
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('bytes');
            $table->boolean('favorite')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
