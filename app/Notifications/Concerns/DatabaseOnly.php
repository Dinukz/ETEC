<?php

namespace App\Notifications\Concerns;

/** All in-app notifications use the database channel only (no mail infra yet). */
trait DatabaseOnly
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }
}
