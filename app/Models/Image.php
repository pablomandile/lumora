<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Imagen generada. Los archivos viven en el disco privado "local".
 *
 * @property string $id
 * @property string $generation_id
 * @property int $user_id
 * @property string $path
 * @property string|null $thumb_path
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property bool $favorite
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 * @property-read Generation $generation
 * @property-read User $user
 */
#[Fillable(['generation_id', 'user_id', 'path', 'thumb_path', 'width', 'height', 'bytes', 'favorite'])]
class Image extends Model
{
    use HasUlids, SoftDeletes;

    /** Días que una imagen borrada queda en la papelera (lo promete la política). */
    public const TRASH_DAYS = 30;

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'favorite' => 'boolean',
        ];
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Borra los archivos del disco (se usa al eliminar definitivamente). */
    public function deleteFiles(): void
    {
        Storage::disk('local')->delete(array_filter([$this->path, $this->thumb_path]));
    }

    protected static function booted(): void
    {
        static::forceDeleting(fn (Image $image) => $image->deleteFiles());
    }
}
