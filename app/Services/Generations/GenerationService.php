<?php

namespace App\Services\Generations;

use App\Jobs\GenerateImage;
use App\Models\AiModel;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Generation;
use App\Models\User;
use App\Services\Images\AttachmentStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Crea pedidos de imagen: valida modelo y tamaño, controla la cuota diaria
 * y encola el Job. Sin conversación, abre una nueva titulada con el prompt.
 *
 * Con imágenes de entrada es una edición: se usa un modelo que las acepte y la
 * salida toma la proporción de la primera.
 */
class GenerationService
{
    public function __construct(private readonly AttachmentStore $attachments) {}

    /**
     * @param  array{prompt: string, negative_prompt?: string|null, model?: string|null, aspect?: string|null, width?: int|null, height?: int|null, seed?: int|null, steps?: int|null, image_ids?: list<string>|null, attachment_ids?: list<string>|null}  $data
     * @param  list<UploadedFile>  $uploads  fotos nuevas, después de las demás
     *
     * @throws QuotaExceededException
     * @throws ValidationException
     */
    public function create(User $user, array $data, ?Conversation $conversation = null, array $uploads = []): Generation
    {
        $imageIds = $data['image_ids'] ?? [];
        $attachmentIds = $data['attachment_ids'] ?? [];
        $references = count($imageIds) + count($attachmentIds) + count($uploads);

        $model = $this->resolveModel($user, $data['model'] ?? null, $references > 0);
        $this->checkReferences($model, $references);

        $remaining = $this->remainingToday($user);
        if ($remaining !== null && $remaining <= 0) {
            throw new QuotaExceededException($user->effectiveDailyQuota() ?? 0);
        }

        // Después de la cuota, para no guardar fotos de un pedido que no va.
        $attachments = $this->collectAttachments($user, $imageIds, $attachmentIds, $uploads);

        if ($attachments !== []) {
            [$width, $height] = self::editSize($attachments[0]->width, $attachments[0]->height);
            $aspect = null;
        } else {
            [$width, $height, $aspect] = $this->resolveSize($user, $data);
        }

        $steps = isset($data['steps']) && $user->isAdmin()
            ? max(1, min((int) $data['steps'], $model->maxSteps()))
            : $model->defaultSteps();

        $conversation ??= $user->conversations()->create([
            'title' => Conversation::titleFromPrompt($data['prompt']),
        ]);

        $generation = $user->generations()->create([
            'conversation_id' => $conversation->id,
            'prompt' => trim($data['prompt']),
            'negative_prompt' => filled($data['negative_prompt'] ?? null) ? trim($data['negative_prompt']) : null,
            'model' => $model->key,
            'width' => $width,
            'height' => $height,
            'aspect' => $aspect,
            'steps' => $steps,
            'seed' => $data['seed'] ?? null,
        ]);

        foreach ($attachments as $i => $attachment) {
            $generation->attachments()->attach($attachment->id, ['position' => $i + 1]);
        }

        // La última usada sube al principio del menú.
        $conversation->touch();

        GenerateImage::dispatch($generation->id);

        return $generation;
    }

    /**
     * Tamaño de salida de una edición: la proporción de la imagen de entrada,
     * cerca de `lumora.edit_megapixels`, en múltiplos de 16.
     *
     * @return array{0: int, 1: int}
     */
    public static function editSize(int $width, int $height): array
    {
        $target = (float) config('lumora.edit_megapixels', 1.0) * 1024 * 1024;
        $scale = sqrt($target / max(1, $width * $height));
        $fit = fn (float $v): int => (int) max(256, min(2048, round($v / 16) * 16));

        return [$fit($width * $scale), $fit($height * $scale)];
    }

    public function usedToday(User $user): int
    {
        $tz = (string) config('lumora.quota_timezone');
        $start = CarbonImmutable::now($tz)->startOfDay()->utc();

        return $user->generations()
            ->countingForQuota()
            ->where('created_at', '>=', $start)
            ->count();
    }

    /** null = sin límite (admin). */
    public function remainingToday(User $user): ?int
    {
        $quota = $user->effectiveDailyQuota();

        return $quota === null ? null : max(0, $quota - $this->usedToday($user));
    }

    private function resolveModel(User $user, ?string $key, bool $withImages): AiModel
    {
        $query = AiModel::query()->availableFor($user);

        if ($key) {
            $model = $query->where('key', $key)->first();
        } else {
            // Sin modelo elegido: el primero que sirva para lo que se pide.
            $model = $query->get()->first(fn (AiModel $m) => $m->editsImages() === $withImages);
        }

        if (! $model) {
            throw ValidationException::withMessages([
                'model' => $withImages && ! $key ? 'Editar imágenes todavía no está disponible.' : 'Ese modelo no está disponible.',
            ]);
        }

        return $model;
    }

    private function checkReferences(AiModel $model, int $count): void
    {
        if ($count > $model->maxImages()) {
            throw ValidationException::withMessages(['images' => $model->maxImages() === 0
                ? "{$model->label} no usa imágenes: quitalas o elegí «Editar imágenes»."
                : "{$model->label} acepta hasta {$model->maxImages()} imágenes."]);
        }

        if ($count < $model->minImages()) {
            throw ValidationException::withMessages(['images' => 'Adjuntá la imagen que querés editar.']);
        }
    }

    /**
     * Los adjuntos en el orden del pedido: de la galería, ya subidos, fotos nuevas.
     *
     * @param  list<string>  $imageIds
     * @param  list<string>  $attachmentIds
     * @param  list<UploadedFile>  $uploads
     * @return list<Attachment>
     */
    private function collectAttachments(User $user, array $imageIds, array $attachmentIds, array $uploads): array
    {
        $attachments = [];

        try {
            foreach ($imageIds as $id) {
                $attachments[] = $this->attachments->fromImage($user->images()->findOrFail($id));
            }

            foreach ($attachmentIds as $id) {
                $attachments[] = $user->attachments()->findOrFail($id);
            }

            foreach ($uploads as $file) {
                $attachments[] = $this->attachments->storeUpload($user, $file);
            }
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['images' => 'No se pudo leer una de las imágenes. Probá con otra.']);
        }

        return $attachments;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int, 2: string|null}
     */
    private function resolveSize(User $user, array $data): array
    {
        /** @var array<string, array{0: int, 1: int}> $aspects */
        $aspects = config('lumora.aspects');
        $aspect = $data['aspect'] ?? null;

        if ($aspect !== null && $aspect !== 'custom') {
            if (! isset($aspects[$aspect])) {
                throw ValidationException::withMessages(['aspect' => 'Esa relación de aspecto no existe.']);
            }

            return [$aspects[$aspect][0], $aspects[$aspect][1], $aspect];
        }

        // Tamaño libre: solo admin, múltiplos de 16 hasta el máximo.
        if (isset($data['width'], $data['height']) && $user->isAdmin()) {
            $max = (int) config('lumora.admin_max_side', 2048);
            $fit = fn (int $v): int => max(256, min($max, intdiv($v, 16) * 16));

            return [$fit((int) $data['width']), $fit((int) $data['height']), null];
        }

        return [$aspects['1:1'][0], $aspects['1:1'][1], '1:1'];
    }
}
