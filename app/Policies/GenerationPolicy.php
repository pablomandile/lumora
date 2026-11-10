<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;

class GenerationPolicy
{
    public function delete(User $user, Generation $generation): bool
    {
        return $generation->user_id === $user->id;
    }
}
