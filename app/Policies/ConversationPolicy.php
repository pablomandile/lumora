<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/** Las conversaciones son privadas: ni el admin entra a las ajenas. */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->user_id === $user->id;
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $conversation->user_id === $user->id;
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $conversation->user_id === $user->id;
    }
}
