<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\GpuStatusController;
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
    Route::get('placa/estado', GpuStatusController::class)->name('gpu.status');
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

        Route::get('ajustes', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('ajustes', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('ajustes/probar-placa', [SettingsController::class, 'testGpu'])->name('settings.test-gpu');
    });

require __DIR__.'/settings.php';
