<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => fake()->sentence(8),
            'model' => 'z-image-turbo',
            'width' => 1024,
            'height' => 1024,
            'aspect' => '1:1',
            'steps' => 9,
            'status' => GenerationStatus::Queued,
        ];
    }

    public function status(GenerationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
