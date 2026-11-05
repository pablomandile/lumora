<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\AccountApproved;
use App\Notifications\NewUserRegistered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_registrations_are_pending_and_notify_the_admins()
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->post(route('register.store'), [
            'name' => 'Nueva',
            'email' => 'nueva@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'nueva@example.com')->firstOrFail();
        $this->assertSame(ApprovalStatus::Pending, $user->approval_status);
        $this->assertSame(UserRole::User, $user->role);
        Notification::assertSentTo($admin, NewUserRegistered::class);
    }

    public function test_the_configured_admin_email_registers_as_approved_admin()
    {
        config(['lumora.admin_email' => 'JEFE@example.com']);

        $this->post(route('register.store'), [
            'name' => 'Jefe',
            'email' => 'jefe@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'jefe@example.com')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->isApproved());
    }

    public function test_pending_users_are_sent_to_the_pending_screen()
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('account.pending'));
        $this->actingAs($user)->get(route('account.pending'))->assertOk();
    }

    public function test_rejected_users_cannot_use_the_app()
    {
        $user = User::factory()->rejected()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('account.pending'));
    }

    public function test_approved_users_leave_the_pending_screen()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.pending'))->assertRedirect(route('dashboard'));
    }

    public function test_non_admins_cannot_open_the_admin_panel()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_admin_can_list_and_approve_users()
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $pending = User::factory()->pending()->create();

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();

        $this->actingAs($admin)->post(route('admin.users.approve', $pending))->assertRedirect();

        $this->assertTrue($pending->fresh()->isApproved());
        Notification::assertSentTo($pending, AccountApproved::class);
    }

    public function test_admin_can_reject_change_role_and_quota()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.users.reject', $user));
        $this->assertSame(ApprovalStatus::Rejected, $user->fresh()->approval_status);

        $this->actingAs($admin)->patch(route('admin.users.quota', $user), ['daily_quota' => 5]);
        $this->assertSame(5, $user->fresh()->daily_quota);
        $this->assertSame(5, $user->fresh()->effectiveDailyQuota());

        $this->actingAs($admin)->patch(route('admin.users.role', $user), ['role' => 'admin']);
        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertTrue($user->fresh()->isApproved());
        $this->assertNull($user->fresh()->effectiveDailyQuota());
    }

    public function test_admin_cannot_reject_or_demote_themselves()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.users.reject', $admin))->assertStatus(422);
        $this->actingAs($admin)->patch(route('admin.users.role', $admin), ['role' => 'user'])->assertStatus(422);
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_make_admin_command()
    {
        $user = User::factory()->pending()->create();

        $this->artisan('lumora:make-admin', ['email' => $user->email])->assertSuccessful();

        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertTrue($user->fresh()->isApproved());
    }
}
