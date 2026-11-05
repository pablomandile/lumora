<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa al usuario que su cuenta fue aprobada.
 */
class AccountApproved extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu cuenta de Lumora fue aprobada')
            ->greeting('¡Hola!')
            ->line('Tu cuenta ya está activa. Ya podés empezar a crear imágenes.')
            ->action('Entrar a Lumora', route('dashboard'));
    }
}
