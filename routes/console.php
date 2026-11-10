<?php

use App\Enums\GenerationStatus;
use App\Enums\UserRole;
use App\Models\AppSetting;
use App\Models\Image;
use App\Models\User;
use App\Services\Generations\GenerationService;
use App\Services\Generations\QuotaExceededException;
use App\Services\ImageEngine\GpuMonitor;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('lumora:make-admin {email}', function (string $email) {
    $user = User::where('email', $email)->first();

    if (! $user) {
        $this->error("No existe un usuario con el email {$email}.");

        return 1;
    }

    $user->forceFill(['role' => UserRole::Admin])->save();
    $user->approve();
    $this->info("{$user->name} ahora es administrador.");

    return 0;
})->purpose('Convierte en administrador (aprobado) a un usuario existente');

Artisan::command('gpu:probe', function (GpuMonitor $monitor) {
    $status = $monitor->refresh();
    $this->line(sprintf(
        '%s — %s%s',
        $status->state,
        $status->message,
        $status->latencyMs !== null ? " ({$status->latencyMs} ms)" : '',
    ));

    return $status->isOnline() ? 0 : 1;
})->purpose('Consulta el estado de la placa y lo guarda para el indicador');

Artisan::command('images:prune {--dry-run : Solo muestra cuántas se borrarían}', function () {
    // Las que borró el usuario se eliminan del todo al vencer la papelera,
    // haya o no retención configurada.
    $trash = Image::onlyTrashed()->where('deleted_at', '<', now()->subDays(Image::TRASH_DAYS));

    if (! $this->option('dry-run')) {
        $trash->each(fn (Image $i) => $i->forceDelete());
    }

    $days = AppSetting::retentionDays();

    if ($days <= 0) {
        $this->info('La retención está desactivada: solo se vació la papelera.');

        return 0;
    }

    $query = Image::withTrashed()
        ->where('favorite', false)
        ->where('created_at', '<', now()->subDays($days));

    if ($this->option('dry-run')) {
        $this->info("Se borrarían {$query->count()} imágenes de más de {$days} días.");

        return 0;
    }

    $deleted = 0;
    $query->chunkById(200, function ($images) use (&$deleted) {
        foreach ($images as $image) {
            $image->forceDelete();
            $deleted++;
        }
    });

    $this->info("Se borraron {$deleted} imágenes de más de {$days} días.");

    return 0;
})->purpose('Borra las imágenes más viejas que la retención configurada (salvo favoritas)');

Artisan::command('lumora:generate {email} {prompt} {--aspect=1:1} {--seed=} {--wait : Procesa la cola y espera el resultado}', function (GenerationService $service, string $email, string $prompt) {
    $user = User::where('email', $email)->first();

    if (! $user) {
        $this->error("No existe un usuario con el email {$email}.");

        return 1;
    }

    try {
        $generation = $service->create($user, [
            'prompt' => $prompt,
            'aspect' => is_string($aspect = $this->option('aspect')) ? $aspect : '1:1',
            'seed' => is_numeric($seed = $this->option('seed')) ? (int) $seed : null,
        ]);
    } catch (QuotaExceededException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $this->info("Generación {$generation->id} encolada ({$generation->width}×{$generation->height}).");

    if (! $this->option('wait')) {
        return 0;
    }

    $this->call('queue:work', ['--stop-when-empty' => true]);
    $generation->refresh();

    if ($generation->status !== GenerationStatus::Done) {
        $this->error("Terminó como «{$generation->status->label()}»: {$generation->error}");

        return 1;
    }

    $this->info("Lista en {$generation->run_seconds} s, seed {$generation->seed}: storage/app/private/{$generation->image->path}");

    return 0;
})->purpose('Genera una imagen desde la terminal (para probar la placa)');

Schedule::command('gpu:probe')->everyMinute()->withoutOverlapping();
Schedule::command('images:prune')->dailyAt('04:00');
