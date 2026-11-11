<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApprovalStatus;
use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\Image;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Uso de la app: totales del período, pedidos por día y uso por usuario.
 */
class StatsController extends Controller
{
    /** Períodos que se pueden elegir, en días. */
    public const RANGES = [7, 30, 90];

    /** Los días se cortan en la hora de Argentina, no en UTC. */
    public const TIMEZONE = 'America/Argentina/Buenos_Aires';

    public function __invoke(Request $request): Response
    {
        $days = (int) $request->integer('dias', 30);
        $days = in_array($days, self::RANGES, true) ? $days : 30;

        $today = now(self::TIMEZONE)->startOfDay();
        $from = $today->copy()->subDays($days - 1);
        $fromUtc = $from->copy()->utc();

        $generations = Generation::query()
            ->where('created_at', '>=', $fromUtc)
            ->get(['user_id', 'status', 'run_seconds', 'created_at']);

        $done = $generations->where('status', GenerationStatus::Done);
        $failed = $generations->where('status', GenerationStatus::Failed);

        return Inertia::render('admin/Stats', [
            'days' => $days,
            'ranges' => self::RANGES,
            'totals' => [
                'requests' => $generations->count(),
                'done' => $done->count(),
                'failed' => $failed->count(),
                'active_users' => $generations->pluck('user_id')->unique()->count(),
                'gpu_seconds' => round((float) $done->sum('run_seconds')),
                'avg_seconds' => $done->whereNotNull('run_seconds')->avg('run_seconds'),
                'new_users' => User::where('created_at', '>=', $fromUtc)->count(),
                'pending_users' => User::where('approval_status', ApprovalStatus::Pending->value)->count(),
                'stored_images' => Image::count(),
                'stored_bytes' => (int) Image::sum('bytes'),
            ],
            'daily' => $this->daily($generations, $from, $days),
            'users' => $this->perUser($fromUtc),
        ]);
    }

    /**
     * Un punto por día del período, incluidos los días sin pedidos.
     *
     * @param  Collection<int, Generation>  $generations
     * @return list<array{date: string, done: int, failed: int, other: int}>
     */
    private function daily($generations, CarbonInterface $from, int $days): array
    {
        $byDay = $generations->groupBy(
            fn (Generation $g): string => $g->created_at?->copy()->setTimezone(self::TIMEZONE)->toDateString() ?? '',
        );

        $points = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $items = $byDay->get($date, collect());
            $done = $items->where('status', GenerationStatus::Done)->count();
            $failed = $items->where('status', GenerationStatus::Failed)->count();

            $points[] = [
                'date' => $date,
                'done' => $done,
                'failed' => $failed,
                'other' => $items->count() - $done - $failed,
            ];
        }

        return $points;
    }

    /**
     * Las cuentas aprobadas (y cualquiera con pedidos en el período), de la
     * más activa a la menos.
     *
     * @return list<array<string, mixed>>
     */
    private function perUser(CarbonInterface $fromUtc): array
    {
        $inRange = fn ($q) => $q->where('created_at', '>=', $fromUtc);

        $users = User::query()
            ->where(fn ($q) => $q
                ->where('approval_status', ApprovalStatus::Approved->value)
                ->orWhereHas('generations', $inRange))
            ->withCount([
                'generations as requests' => $inRange,
                'generations as failed' => fn ($q) => $inRange($q)
                    ->where('status', GenerationStatus::Failed->value),
                'images',
                'conversations',
            ])
            ->withSum(['generations as gpu_seconds' => $inRange], 'run_seconds')
            ->withSum('images as stored_bytes', 'bytes')
            ->withMax('generations as last_request_at', 'created_at')
            ->addSelect(['last_seen' => DB::table('sessions')
                ->selectRaw('max(last_activity)')
                ->whereColumn('sessions.user_id', 'users.id')])
            ->orderByDesc('requests')
            ->orderByDesc('last_request_at')
            ->limit(200)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->isAdmin(),
                'requests' => (int) $user->getAttribute('requests'),
                'failed' => (int) $user->getAttribute('failed'),
                'gpu_seconds' => round((float) $user->getAttribute('gpu_seconds')),
                'images' => (int) $user->getAttribute('images_count'),
                'stored_bytes' => (int) $user->getAttribute('stored_bytes'),
                'conversations' => (int) $user->getAttribute('conversations_count'),
                'last_request_at' => $this->iso($user->getAttribute('last_request_at')),
                'last_seen_at' => $user->getAttribute('last_seen')
                    ? Carbon::createFromTimestamp((int) $user->getAttribute('last_seen'))->toIso8601String()
                    : null,
                'created_at' => $user->created_at?->toIso8601String(),
            ]);

        return array_values($users->all());
    }

    private function iso(mixed $value): ?string
    {
        return $value ? Carbon::parse((string) $value, 'UTC')->toIso8601String() : null;
    }
}
