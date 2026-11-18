<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\DatabaseOnly;
use Illuminate\Notifications\Notification;

/**
 * In-app bell for the referring consultant once their student's payment is
 * verified. The EMAIL is sent separately via App\Services\TransactionalMail.
 */
class StudentPaymentConfirmed extends Notification
{
    use DatabaseOnly;

    public function __construct(protected Payment $payment)
    {
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Payment confirmed',
            'message' => "Payment for {$this->payment->user?->name} ({$this->statusLabel()}) has been confirmed.",
            'url' => route('consultant.students.index'),
        ];
    }

    protected function statusLabel(): string
    {
        return match ($this->payment->payment_type) {
            'full' => 'Full Paid',
            'half' => 'Partial Paid',
            'free_card' => 'Free',
            'balance' => 'Balance Paid',
            default => 'Verified',
        };
    }
}
