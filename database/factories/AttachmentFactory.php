<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        $id = Str::lower((string) Str::ulid());

        return [
            'user_id' => User::factory(),
            'path' => "attachments/test/{$id}.jpg",
            'thumb_path' => "attachments/test/{$id}_thumb.webp",
            'width' => 800,
            'height' => 1000,
            'bytes' => 120_000,
            'sha256' => hash('sha256', $id),
        ];
    }
}
