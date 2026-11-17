<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

class AttachmentPolicy
{
    /** Igual que las imágenes: el dueño, y el admin para revisar los pedidos. */
    public function view(User $user, Attachment $attachment): bool
    {
        return $attachment->user_id === $user->id || $user->isAdmin();
    }

    /** Usarla en un pedido nuevo: solo el dueño. */
    public function use(User $user, Attachment $attachment): bool
    {
        return $attachment->user_id === $user->id;
    }
}
