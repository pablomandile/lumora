<?php

use App\Http\Controllers\Admin\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'Welcome')->name('home');

// Pantalla para cuentas que el admin todavía no aprobó.
Route::middleware(['auth', 'verified'])->get('cuenta/pendiente', function (Request $request) {
    if ($request->user()->isApproved()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('account/Pending', [
        'status' => $request->user()->approval_status->value,
    ]);
})->name('account.pending');

Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('usuarios', [UserController::class, 'index'])->name('users.index');
        Route::post('usuarios/{user}/aprobar', [UserController::class, 'approve'])->name('users.approve');
        Route::post('usuarios/{user}/rechazar', [UserController::class, 'reject'])->name('users.reject');
        Route::patch('usuarios/{user}/rol', [UserController::class, 'updateRole'])->name('users.role');
        Route::patch('usuarios/{user}/cuota', [UserController::class, 'updateQuota'])->name('users.quota');
    });

require __DIR__.'/settings.php';
