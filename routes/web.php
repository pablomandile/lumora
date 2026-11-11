<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\GpuStatusController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\Legales\LegalesController;
use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\StudioController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'Welcome')->name('home');

// Ingreso con Google. `guest`: con la sesión abierta, rehacer el flujo solo
// serviría para cambiar de cuenta sin cerrar la sesión.
Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
});

// Textos legales: se leen con sesión y sin ella.
Route::get('terminos', [LegalesController::class, 'terminos'])->name('terminos');
Route::get('privacidad', [LegalesController::class, 'privacidad'])->name('privacidad');

Route::middleware('auth')->group(function () {
    Route::get('terminos/aceptar', [LegalesController::class, 'aceptar'])->name('terminos.aceptar');
    Route::post('terminos/aceptar', [LegalesController::class, 'guardarAceptacion'])->name('terminos.aceptar.store');
    // Derecho de acceso: no depende de estar aprobado ni de aceptar los términos.
    Route::get('settings/mis-datos', DataExportController::class)->name('exportacion.datos');
});

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
    // El "dashboard" es el estudio: el nombre de ruta queda por Fortify y los mails.
    Route::get('estudio', [StudioController::class, 'index'])->name('dashboard');
    Route::get('estudio/{conversation}', [StudioController::class, 'show'])->name('conversations.show');
    Route::patch('conversaciones/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
    Route::delete('conversaciones/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');
    Route::post('estudio', [StudioController::class, 'store'])->middleware('throttle:generate')->name('generations.store');
    Route::delete('generaciones/{generation}', [StudioController::class, 'destroy'])->name('generations.destroy');

    Route::get('galeria', [GalleryController::class, 'index'])->name('gallery');

    Route::get('imagenes/{image}', [ImageController::class, 'show'])->name('images.show');
    Route::get('imagenes/{image}/miniatura', [ImageController::class, 'thumb'])->name('images.thumb');
    Route::get('imagenes/{image}/descargar/{format}', [ImageController::class, 'download'])
        ->whereIn('format', ['png', 'jpg', 'webp'])
        ->middleware('throttle:60,1')
        ->name('images.download');
    Route::patch('imagenes/{image}/favorita', [ImageController::class, 'favorite'])->name('images.favorite');
    Route::delete('imagenes/{image}', [ImageController::class, 'destroy'])->name('images.destroy');

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

        Route::get('estadisticas', StatsController::class)->name('stats');

        Route::get('ajustes', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('ajustes', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('ajustes/probar-placa', [SettingsController::class, 'testGpu'])->name('settings.test-gpu');
    });

require __DIR__.'/settings.php';
