<?php

namespace App\Policies;

use App\Models\Image;
use App\Models\User;

class ImagePolicy
{
    /** El dueño la ve; el admin también, para poder revisar lo que se genera. */
    public function view(User $user, Image $image): bool
    {
        return $image->user_id === $user->id || $user->isAdmin();
    }

    public function update(User $user, Image $image): bool
    {
        return $image->user_id === $user->id;
    }

    public function delete(User $user, Image $image): bool
    {
        return $image->user_id === $user->id;
    }
}
