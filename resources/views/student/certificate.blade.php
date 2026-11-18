<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate — {{ $certificate->certificate_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Georgia, 'Times New Roman', serif; background: #f1f5f9; display: flex; flex-direction: column; align-items: center; padding: 24px; }
        .actions { margin-bottom: 16px; display: flex; gap: 8px; }
        .actions a, .actions button { font-family: system-ui, sans-serif; font-size: 14px; font-weight: 600; padding: 8px 20px; border-radius: 8px; border: none; cursor: pointer; text-decoration: none; }
        .btn-print { background: #1d4ed8; color: #fff; }
        .btn-back { background: #e2e8f0; color: #0f172a; }
        .certificate { width: 1000px; max-width: 100%; background: #fff; border: 14px double #1e3a8a; padding: 60px 70px; text-align: center; position: relative; }
        .brand { font-family: system-ui, sans-serif; font-size: 20px; font-weight: 800; letter-spacing: 6px; color: #1e3a8a; text-transform: uppercase; }
        .heading { font-size: 44px; margin-top: 28px; color: #0f172a; letter-spacing: 2px; }
        .sub { font-family: system-ui, sans-serif; font-size: 14px; color: #64748b; margin-top: 10px; letter-spacing: 3px; text-transform: uppercase; }
        .name { font-size: 38px; color: #1e3a8a; margin-top: 30px; font-style: italic; border-bottom: 2px solid #cbd5e1; display: inline-block; padding: 0 40px 8px; }
        .course { font-size: 22px; color: #0f172a; margin-top: 26px; }
        .meta { font-family: system-ui, sans-serif; font-size: 13px; color: #64748b; margin-top: 36px; display: flex; justify-content: space-between; }
        .meta strong { color: #0f172a; }
        .verify { font-family: system-ui, sans-serif; margin-top: 14px; font-size: 11px; color: #94a3b8; }
        @media print {
            body { background: #fff; padding: 0; }
            .actions { display: none; }
            .certificate { border-width: 12px; width: 100%; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <a href="{{ route('student.certificates') }}" class="btn-back">← Back</a>
        <button class="btn-print" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="certificate">
        <div class="brand">{{ config('app.name', 'ETEC') }} Academy</div>
        <h1 class="heading">Certificate of Completion</h1>
        <p class="sub">This certificate is proudly presented to</p>
        <div class="name">{{ $certificate->user->name }}</div>
        <p class="course">for successfully completing<br><strong>{{ $certificate->course->title }}</strong></p>
        <div class="meta">
            <span>Certificate No: <strong>{{ $certificate->certificate_number }}</strong></span>
            <span>Issued: <strong>{{ optional($certificate->issued_at)->format('F d, Y') }}</strong></span>
        </div>
        <p class="verify">Verify this certificate at {{ route('certificates.verify') }} using code {{ $certificate->verification_code }}</p>
    </div>
</body>
</html>
