<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class WelcomeStudentCredentials extends Notification
{
    public function __construct(protected ?string $username = null)
    {
    }

    // Email is sent via App\Services\TransactionalMail (Mail::html) — NOT the
    // notification mail channel, whose markdown pipeline fails on this host.
    // This notification is now the in-app bell only.
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Welcome to ETEC',
            'message' => 'Your student account has been created. Check your email for login details.',
            'url' => route('login'),
        ];
    }
}
