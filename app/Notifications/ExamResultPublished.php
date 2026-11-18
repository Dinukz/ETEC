<?php

namespace App\Notifications;

use App\Models\ExamResult;
use App\Notifications\Concerns\DatabaseOnly;
use Illuminate\Notifications\Notification;

class ExamResultPublished extends Notification
{
    use DatabaseOnly;

    public function __construct(protected ExamResult $result)
    {
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Exam result released',
            'message' => "Your result for \"{$this->result->exam->title}\" is out: {$this->result->marks_obtained}/{$this->result->total_marks} ({$this->result->grade}) — ".($this->result->passed ? 'Passed' : 'Not passed').'.',
            'url' => route('student.exams.results'),
        ];
    }
}
