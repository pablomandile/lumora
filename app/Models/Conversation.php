<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Un hilo del estudio: los pedidos de imagen que se hicieron juntos.
 *
 * @property string $id
 * @property int $user_id
 * @property string $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['title'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUlids;

    public const TITLE_MAX = 60;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Generation, $this> */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /** Título automático: el comienzo del primer prompt, cortado en una palabra. */
    public static function titleFromPrompt(string $prompt): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $prompt));

        if ($clean === '') {
            return 'Conversación nueva';
        }

        $title = Str::words($clean, 8, '');
        $cut = $title !== $clean;

        if (mb_strlen($title) > self::TITLE_MAX) {
            $title = rtrim(mb_substr($title, 0, self::TITLE_MAX - 1));
            $cut = true;
        }

        return Str::ucfirst($title).($cut ? '…' : '');
    }
}
