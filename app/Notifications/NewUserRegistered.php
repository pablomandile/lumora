<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa al admin que hay una cuenta nueva esperando aprobación.
 */
class NewUserRegistered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $newUser) {}

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
            ->subject('Lumora: nueva cuenta para aprobar')
            ->greeting('Hola')
            ->line("{$this->newUser->name} ({$this->newUser->email}) se registró en Lumora y está esperando tu aprobación.")
            ->action('Revisar usuarios', route('admin.users.index'));
    }
}
