<?php

namespace App\Notifications;

use App\Notifications\Concerns\DatabaseOnly;
use Illuminate\Notifications\Notification;

/**
 * Sent to every active consultant once a System Run finishes — the
 * confirmation half of the pair, after SystemRunScheduled's pre-run notice.
 *
 * Fires automatically from SystemRunController::run() the moment a correctly
 * confirmed run completes, so nobody has to remember to send it by hand.
 */
class SystemRunCompleted extends Notification
{
    use DatabaseOnly;

    public function __construct(protected string $settledDateLabel)
    {
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->settledDateLabel.' System Run Has Been Done',
            'message' => $this->settledDateLabel.' System Run has been done. Any service group that balanced has been settled.',
            'url' => route('consultant.groups.index'),
        ];
    }
}
