<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de imagen que ofrece el gateway (z-image-turbo, qwen-image-edit).
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string|null $description
 * @property array<string, mixed>|null $defaults
 * @property bool $enabled
 * @property bool $admin_only
 * @property int $sort
 */
#[Fillable(['key', 'label', 'description', 'defaults', 'enabled', 'admin_only', 'sort'])]
class AiModel extends Model
{
    protected function casts(): array
    {
        return [
            'defaults' => 'array',
            'enabled' => 'boolean',
            'admin_only' => 'boolean',
        ];
    }

    /**
     * Modelos que puede usar este usuario.
     *
     * @param  Builder<AiModel>  $query
     */
    #[Scope]
    protected function availableFor(Builder $query, User $user): void
    {
        $query->where('enabled', true)
            ->when(! $user->isAdmin(), fn ($q) => $q->where('admin_only', false))
            ->orderBy('sort');
    }

    public function defaultSteps(): int
    {
        return (int) ($this->defaults['steps'] ?? 9);
    }

    public function maxSteps(): int
    {
        return (int) ($this->defaults['max_steps'] ?? 20);
    }

    /** Imágenes de entrada que necesita como mínimo (los de edición, al menos 1). */
    public function minImages(): int
    {
        return (int) ($this->defaults['min_images'] ?? 0);
    }

    /** 0 = texto a imagen, no acepta imágenes. */
    public function maxImages(): int
    {
        return (int) ($this->defaults['max_images'] ?? 0);
    }

    public function editsImages(): bool
    {
        return $this->maxImages() > 0;
    }
}
