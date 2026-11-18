<?php

namespace App\Notifications;

use App\Models\Commission;
use App\Notifications\Concerns\DatabaseOnly;
use Illuminate\Notifications\Notification;

class ServiceIncomePaid extends Notification
{
    use DatabaseOnly;

    public function __construct(protected Commission $commission)
    {
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Service Income paid',
            'message' => 'A payout of LKR '.number_format((float) $this->commission->amount).' has been marked as paid to your account.',
            'url' => route('consultant.earnings.index'),
        ];
    }
}
