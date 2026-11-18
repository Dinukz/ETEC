<?php

namespace App\Notifications;

use App\Models\Commission;
use App\Notifications\Concerns\DatabaseOnly;
use Illuminate\Notifications\Notification;

class ServiceIncomeApproved extends Notification
{
    use DatabaseOnly;

    public function __construct(protected Commission $commission)
    {
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Service Income approved',
            'message' => 'Your service income of LKR '.number_format((float) $this->commission->amount).' has been approved for payout.',
            'url' => route('consultant.earnings.index'),
        ];
    }
}
