<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Http\Request;

class CertificateVerifyController extends Controller
{
    /** Public certificate verification by code. */
    public function show(Request $request)
    {
        $code = trim((string) $request->query('code', ''));
        $certificate = null;
        $searched = $code !== '';

        if ($searched) {
            $certificate = Certificate::with(['user', 'course'])
                ->where('verification_code', $code)
                ->orWhere('certificate_number', $code)
                ->first();

            if ($certificate && $certificate->status !== 'issued') {
                $certificate = null;
            }
        }

        return view('pages.verify-certificate', compact('certificate', 'code', 'searched'));
    }
}
