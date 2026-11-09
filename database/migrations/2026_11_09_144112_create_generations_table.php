<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->text('negative_prompt')->nullable();
            $table->string('model', 64);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('aspect', 16)->nullable();
            $table->unsignedTinyInteger('steps')->nullable();
            // Pedida por el usuario (null = al azar) y la que usó el gateway.
            $table->unsignedBigInteger('seed')->nullable();
            // queued | waiting_gpu | running | done | failed
            $table->string('status', 16)->default('queued');
            $table->string('remote_job_id', 64)->nullable();
            $table->decimal('progress', 4, 2)->nullable();
            $table->unsignedSmallInteger('queue_position')->nullable();
            $table->string('error', 500)->nullable();
            $table->decimal('run_seconds', 7, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
