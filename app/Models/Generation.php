<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Un pedido de imagen. Lo procesa el Job GenerateImage contra el gateway.
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $conversation_id
 * @property string $prompt
 * @property string|null $negative_prompt
 * @property string $model
 * @property int $width
 * @property int $height
 * @property string|null $aspect
 * @property int|null $steps
 * @property int|null $seed
 * @property GenerationStatus $status
 * @property string|null $remote_job_id
 * @property float|null $progress
 * @property int|null $queue_position
 * @property string|null $error
 * @property float|null $run_seconds
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Conversation|null $conversation
 * @property-read Image|null $image
 */
#[Fillable([
    'user_id', 'conversation_id', 'prompt', 'negative_prompt', 'model', 'width', 'height', 'aspect', 'steps', 'seed',
    'status', 'remote_job_id', 'progress', 'queue_position', 'error', 'run_seconds', 'started_at', 'finished_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'status' => 'queued',
    ];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'width' => 'integer',
            'height' => 'integer',
            'steps' => 'integer',
            'seed' => 'integer',
            'progress' => 'float',
            'queue_position' => 'integer',
            'run_seconds' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return HasOne<Image, $this> */
    public function image(): HasOne
    {
        return $this->hasOne(Image::class);
    }

    /**
     * Las que cuentan para la cuota: todo menos las que fallaron.
     *
     * @param  Builder<Generation>  $query
     */
    #[Scope]
    protected function countingForQuota(Builder $query): void
    {
        $query->where('status', '!=', GenerationStatus::Failed->value);
    }

    /** @param  Builder<Generation>  $query */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereIn('status', [
            GenerationStatus::Queued->value,
            GenerationStatus::WaitingGpu->value,
            GenerationStatus::Running->value,
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => GenerationStatus::Failed,
            'error' => mb_substr($message, 0, 500),
            'finished_at' => now(),
            'progress' => null,
            'queue_position' => null,
        ])->save();
    }
}
