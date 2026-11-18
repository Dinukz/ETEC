<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\DatabaseOnly;
use Illuminate\Notifications\Notification;

class PaymentRejected extends Notification
{
    use DatabaseOnly;

    public function __construct(protected Payment $payment, protected ?string $remarks = null)
    {
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Payment rejected',
            'message' => "Your payment {$this->payment->payment_code} was rejected".($this->remarks ? ": {$this->remarks}" : '.').' Please contact support or submit a new payment.',
            'url' => route('contact'),
        ];
    }
}
