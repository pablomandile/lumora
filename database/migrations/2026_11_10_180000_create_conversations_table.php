<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::table('generations', function (Blueprint $table) {
            // Al borrar una conversación las imágenes quedan en la galería.
            $table->foreignUlid('conversation_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
        });

        // Lo generado antes de que existieran las conversaciones queda junto,
        // en una por usuario, para que no desaparezca del estudio.
        $users = DB::table('generations')->whereNull('conversation_id')->distinct()->pluck('user_id');

        foreach ($users as $userId) {
            $last = DB::table('generations')->where('user_id', $userId)->max('created_at');
            $id = (string) Str::ulid();

            DB::table('conversations')->insert([
                'id' => $id,
                'user_id' => $userId,
                'title' => 'Imágenes anteriores',
                'created_at' => $last,
                'updated_at' => $last,
            ]);

            DB::table('generations')->where('user_id', $userId)->whereNull('conversation_id')
                ->update(['conversation_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
        });

        Schema::dropIfExists('conversations');
    }
};
