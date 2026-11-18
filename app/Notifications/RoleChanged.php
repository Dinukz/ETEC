<?php

namespace App\Notifications;

use App\Notifications\Concerns\DatabaseOnly;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * In-app bell for a user whose role an admin changed. The EMAIL is sent
 * separately via App\Services\TransactionalMail (Mail::html), matching
 * PaymentVerified and every other transactional message in this app —
 * the notification mail pipeline is unreliable on this host.
 */
class RoleChanged extends Notification
{
    use DatabaseOnly;

    public function __construct(protected string $role, protected ?string $previousRole = null)
    {
    }

    public function toDatabase(object $notifiable): array
    {
        $label = Str::headline($this->role);

        return [
            'title' => 'Your role has changed',
            'message' => $this->previousRole
                ? "An administrator changed your role from {$this->previousRole} to {$label}."
                : "An administrator set your role to {$label}.",
            'url' => route('dashboard'),
        ];
    }
}
