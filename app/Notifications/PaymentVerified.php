<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\DatabaseOnly;
use Illuminate\Notifications\Notification;

/**
 * In-app bell for the student when their payment is verified. The confirmation
 * EMAIL is sent separately via App\Services\TransactionalMail (Mail::html),
 * because the notification mail pipeline is unreliable on this host.
 */
class PaymentVerified extends Notification
{
    use DatabaseOnly;

    public function __construct(protected Payment $payment)
    {
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Payment verified',
            'message' => "Your payment {$this->payment->payment_code} for \"{$this->payment->course->title}\" was verified. Your course is now unlocked!",
            'url' => route('student.courses'),
        ];
    }
}
