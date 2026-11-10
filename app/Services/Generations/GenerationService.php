<?php

namespace App\Services\Generations;

use App\Jobs\GenerateImage;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Crea pedidos de imagen: valida modelo y tamaño, controla la cuota diaria
 * y encola el Job. Sin conversación, abre una nueva titulada con el prompt.
 */
class GenerationService
{
    /**
     * @param  array{prompt: string, negative_prompt?: string|null, model?: string|null, aspect?: string|null, width?: int|null, height?: int|null, seed?: int|null, steps?: int|null}  $data
     *
     * @throws QuotaExceededException
     * @throws ValidationException
     */
    public function create(User $user, array $data, ?Conversation $conversation = null): Generation
    {
        $model = $this->resolveModel($user, $data['model'] ?? null);
        [$width, $height, $aspect] = $this->resolveSize($user, $data);

        $remaining = $this->remainingToday($user);
        if ($remaining !== null && $remaining <= 0) {
            throw new QuotaExceededException($user->effectiveDailyQuota() ?? 0);
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

        // La última usada sube al principio del menú.
        $conversation->touch();

        GenerateImage::dispatch($generation->id);

        return $generation;
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

    private function resolveModel(User $user, ?string $key): AiModel
    {
        $query = AiModel::query()->availableFor($user);
        $model = $key ? (clone $query)->where('key', $key)->first() : $query->first();

        if (! $model) {
            throw ValidationException::withMessages(['model' => 'Ese modelo no está disponible.']);
        }

        return $model;
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
