<?php

namespace App\Notifications;

use App\Models\AssignmentSubmission;
use App\Notifications\Concerns\DatabaseOnly;
use Illuminate\Notifications\Notification;

class AssignmentGraded extends Notification
{
    use DatabaseOnly;

    public function __construct(protected AssignmentSubmission $submission)
    {
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Assignment graded',
            'message' => "\"{$this->submission->assignment->title}\" was graded: {$this->submission->marks_obtained}/{$this->submission->assignment->total_marks}.",
            'url' => route('student.assignments.show', $this->submission->assignment_id),
        ];
    }
}
