<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\GenerationService;
use App\Services\Generations\QuotaExceededException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla principal: una conversación con los pedidos de imagen y sus
 * resultados. `/estudio` es una conversación nueva, todavía sin guardar: se
 * crea con el primer pedido.
 */
class StudioController extends Controller
{
    /** Los últimos pedidos de una conversación que se muestran. */
    private const THREAD = 100;

    public function index(Request $request, GenerationService $service): Response
    {
        return $this->render($request, $service, null);
    }

    public function show(Request $request, GenerationService $service, Conversation $conversation): Response
    {
        Gate::authorize('view', $conversation);

        return $this->render($request, $service, $conversation);
    }

    private function render(Request $request, GenerationService $service, ?Conversation $conversation): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<string, array{0: int, 1: int}> $aspects */
        $aspects = config('lumora.aspects');

        $aspect = $request->query('aspect');
        $seed = $request->query('seed');

        return Inertia::render('Studio', [
            'conversation' => $conversation ? ['id' => $conversation->id, 'title' => $conversation->title] : null,
            'generations' => fn () => $conversation ? $this->thread($conversation, $request) : [],
            'quota' => fn () => $this->quota($user, $service),
            'models' => AiModel::query()->availableFor($user)->get()->map(fn (AiModel $m) => [
                'key' => $m->key,
                'label' => $m->label,
                'default_steps' => $m->defaultSteps(),
                'max_steps' => $m->maxSteps(),
            ])->values(),
            'aspects' => collect($aspects)
                ->map(fn (array $size, string $key) => ['key' => $key, 'width' => $size[0], 'height' => $size[1]])
                ->values(),
            'max_side' => (int) config('lumora.admin_max_side', 2048),
            // "Reusar" desde la galería: el prompt (y la seed) llegan por la URL.
            'prefill' => [
                'prompt' => mb_substr((string) $request->string('prompt'), 0, 2000),
                'aspect' => is_string($aspect) && isset($aspects[$aspect]) ? $aspect : null,
                'seed' => is_numeric($seed) ? (int) $seed : null,
            ],
        ]);
    }

    public function store(StoreGenerationRequest $request, GenerationService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{prompt: string, negative_prompt?: string|null, model?: string|null, aspect?: string|null, width?: int|null, height?: int|null, seed?: int|null, steps?: int|null} $data */
        $data = $request->validated();

        $conversation = $request->filled('conversation_id')
            ? $user->conversations()->findOrFail($request->string('conversation_id')->toString())
            : null;

        try {
            $generation = $service->create($user, $data, $conversation);
        } catch (QuotaExceededException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return $conversation ? to_route('conversations.show', $conversation) : to_route('dashboard');
        }

        return to_route('conversations.show', $generation->conversation_id);
    }

    /** Descarta una generación que falló (las que fallan no cuentan para la cuota). */
    public function destroy(Generation $generation): RedirectResponse
    {
        Gate::authorize('delete', $generation);

        abort_unless($generation->status === GenerationStatus::Failed, 409, 'Solo se pueden descartar las que fallaron.');

        $generation->delete();

        return back();
    }

    /**
     * Los pedidos de la conversación, del más viejo al más nuevo (como un chat).
     *
     * @return array<int, array<string, mixed>>
     */
    private function thread(Conversation $conversation, Request $request): array
    {
        // Las terminadas cuya imagen se borró no se muestran.
        return $conversation->generations()
            ->with('image')
            ->where(fn ($q) => $q->where('status', '!=', GenerationStatus::Done->value)->orWhereHas('image'))
            ->latest()
            ->latest('id')
            ->limit(self::THREAD)
            ->get()
            ->reverse()
            ->map(fn (Generation $g) => (new GenerationResource($g))->resolve($request))
            ->values()
            ->all();
    }

    /** @return array{limit: int|null, used: int, remaining: int|null} */
    private function quota(User $user, GenerationService $service): array
    {
        return [
            'limit' => $user->effectiveDailyQuota(),
            'used' => $service->usedToday($user),
            'remaining' => $service->remainingToday($user),
        ];
    }
}
