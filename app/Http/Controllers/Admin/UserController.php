<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use App\Notifications\AccountApproved;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $users = User::query()
            ->when(
                in_array($status, array_column(ApprovalStatus::cases(), 'value'), true),
                fn ($q) => $q->where('approval_status', $status),
            )
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q')->toString().'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            // Primero los pendientes, después los más nuevos.
            ->orderByRaw("approval_status = 'pending' desc")
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->email_verified_at !== null,
                'role' => $user->role->value,
                'approval_status' => $user->approval_status->value,
                'approved_at' => $user->approved_at?->toIso8601String(),
                'daily_quota' => $user->daily_quota,
                'created_at' => $user->created_at?->toIso8601String(),
                'is_self' => $user->is($request->user()),
            ]);

        return Inertia::render('admin/Users', [
            'users' => $users,
            'filters' => ['status' => $status, 'q' => $request->string('q')->toString()],
            'pendingCount' => User::where('approval_status', ApprovalStatus::Pending->value)->count(),
            'defaultDailyQuota' => AppSetting::defaultDailyQuota(),
        ]);
    }

    public function approve(User $user): RedirectResponse
    {
        $wasApproved = $user->approval_status === ApprovalStatus::Approved;
        $user->approve();

        if (! $wasApproved) {
            $user->notify(new AccountApproved);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Aprobaste a {$user->name}."]);

        return back();
    }

    public function reject(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, 'No podés rechazar tu propia cuenta.');

        $user->reject();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Rechazaste a {$user->name}."]);

        return back();
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        abort_if($user->is($request->user()), 422, 'No podés cambiar tu propio rol.');

        $user->forceFill(['role' => $data['role']])->save();

        if ($user->isAdmin() && $user->approval_status !== ApprovalStatus::Approved) {
            $user->approve();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rol actualizado.']);

        return back();
    }

    public function updateQuota(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'daily_quota' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $user->forceFill(['daily_quota' => $data['daily_quota']])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cuota actualizada.']);

        return back();
    }
}
