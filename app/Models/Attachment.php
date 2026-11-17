<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Imagen de entrada para editar: una foto que subió el usuario o una copia de
 * una imagen suya de la galería. Ya reducida y sin EXIF (ver AttachmentStore).
 *
 * @property string $id
 * @property int $user_id
 * @property string $path
 * @property string|null $thumb_path
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property string $sha256
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'path', 'thumb_path', 'width', 'height', 'bytes', 'sha256'])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, HasUlids;

    /** Imágenes de entrada por pedido (lo que acepta el modelo de edición). */
    public const MAX_PER_GENERATION = 3;

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Generation, $this> */
    public function generations(): BelongsToMany
    {
        return $this->belongsToMany(Generation::class)->withPivot('position');
    }

    public function deleteFiles(): void
    {
        Storage::disk('local')->delete(array_filter([$this->path, $this->thumb_path]));
    }

    protected static function booted(): void
    {
        static::deleting(fn (Attachment $attachment) => $attachment->deleteFiles());
    }
}
