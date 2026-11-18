<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Certificate;

class CertificateController extends Controller
{
    /** Printable certificate — owner or staff only. */
    public function show(Certificate $certificate)
    {
        $user = auth()->user();
        abort_unless($certificate->user_id === $user->id || $user->isStaff(), 403);
        abort_unless($certificate->status === 'issued', 404);

        $certificate->load(['user', 'course']);

        return view('student.certificate', compact('certificate'));
    }
}
