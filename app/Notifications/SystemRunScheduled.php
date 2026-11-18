<?php

namespace App\Notifications;

use App\Notifications\Concerns\DatabaseOnly;
use Illuminate\Notifications\Notification;

/**
 * The heads-up every consultant gets before a System Run.
 *
 * The run settles yesterday's activations and permanently forfeits whatever is
 * left unmatched on a leg, so consultants need a window to clear pending
 * payments and report mistakes first. This is the in-app half of the notice the
 * accounts unit already sends by hand.
 */
class SystemRunScheduled extends Notification
{
    use DatabaseOnly;

    public function __construct(
        protected string $runAtLabel,
        protected string $activationsDate,
        protected string $errorsDate,
    ) {
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'System Run today at '.$this->runAtLabel,
            'message' => $this->activationsDate.' activations are done. The System Run will be at '
                .$this->runAtLabel.'. Before then, check your pending students and report anything wrong with '
                .$this->errorsDate.' in the consultant group.',
            'url' => route('consultant.students.pending'),
        ];
    }
}
