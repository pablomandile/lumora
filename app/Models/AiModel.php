<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de imagen que ofrece el gateway (z-image-turbo, más adelante qwen-image).
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
}
