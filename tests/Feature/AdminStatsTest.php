<?php

use App\Enums\ApprovalStatus;
use App\Enums\GenerationStatus;
use App\Models\Conversation;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('solo el admin ve las estadísticas', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.stats'))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.stats'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Stats')
            ->where('days', 30)
            ->where('ranges', [7, 30, 90])
            ->has('daily', 30));
});

it('cuenta el uso del período, por día y por usuario', function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(15, 0));

    $admin = User::factory()->admin()->create();
    $ana = User::factory()->create(['name' => 'Ana']);
    $beto = User::factory()->create(['name' => 'Beto']);
    User::factory()->create(['approval_status' => ApprovalStatus::Pending]);
    Conversation::factory()->for($ana)->create();

    Generation::factory()->for($ana)->status(GenerationStatus::Done)
        ->count(3)->create(['run_seconds' => 20, 'created_at' => now()->subDay()]);
    Generation::factory()->for($ana)->status(GenerationStatus::Failed)
        ->create(['created_at' => now()]);
    Generation::factory()->for($beto)->status(GenerationStatus::Done)
        ->create(['run_seconds' => 10, 'created_at' => now()]);
    // Fuera de los 7 días: no cuenta.
    Generation::factory()->for($beto)->status(GenerationStatus::Done)
        ->create(['run_seconds' => 99, 'created_at' => now()->subDays(8)]);

    DB::table('sessions')->insert([
        'id' => 'abc', 'user_id' => $ana->id, 'ip_address' => null, 'user_agent' => null,
        'payload' => '', 'last_activity' => now()->subHour()->timestamp,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.stats', ['dias' => 7]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('days', 7)
            ->where('totals.requests', 5)
            ->where('totals.done', 4)
            ->where('totals.failed', 1)
            ->where('totals.active_users', 2)
            ->where('totals.gpu_seconds', 70)
            ->where('totals.avg_seconds', 17.5)
            ->where('totals.pending_users', 1)
            ->has('daily', 7)
            ->where('daily.5', ['date' => '2026-11-09', 'done' => 3, 'failed' => 0, 'other' => 0])
            ->where('daily.6', ['date' => '2026-11-10', 'done' => 1, 'failed' => 1, 'other' => 0])
            // Los aprobados, de más a menos activo; el pendiente sin pedidos no figura.
            ->has('users', 3)
            ->where('users.0.name', 'Ana')
            ->where('users.0.requests', 4)
            ->where('users.0.failed', 1)
            ->where('users.0.gpu_seconds', 60)
            ->where('users.0.conversations', 1)
            ->where('users.0.last_seen_at', fn ($v) => $v !== null)
            ->where('users.1.name', 'Beto')
            ->where('users.1.requests', 1)
            ->where('users.2.id', $admin->id)
            ->where('users.2.last_seen_at', null));
});

it('corta los días en la hora argentina', function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
    $user = User::factory()->create();

    // 01:00 UTC del 10 = 22:00 del 9 en Buenos Aires.
    Generation::factory()->for($user)->status(GenerationStatus::Done)
        ->create(['created_at' => now()->setTime(1, 0)]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.stats', ['dias' => 7]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('daily.5.date', '2026-11-09')
            ->where('daily.5.done', 1)
            ->where('daily.6.done', 0));
});

it('un período que no está en la lista vuelve a 30 días', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.stats', ['dias' => 3650]))
        ->assertInertia(fn (Assert $page) => $page->where('days', 30)->has('daily', 30));
});
