<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; padding: 16px; color: #111; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        p.sub { color: #555; margin: 0 0 16px; font-size: 12px; }
        .scroll { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 480px; }
        th, td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; white-space: nowrap; }
        th { background: #f3f4f6; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .btn {
            display: inline-block; padding: 12px 18px; font-size: 15px; font-weight: 600;
            border-radius: 8px; border: 0; cursor: pointer; text-decoration: none;
            background: #2563eb; color: #fff;
        }
        .btn.alt { background: #e2e8f0; color: #0f172a; }
        .hint { font-size: 12px; color: #64748b; margin: 0 0 16px; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; }
            th, td { white-space: normal; }
            table { min-width: 0; }
        }
        @media (min-width: 640px) { body { padding: 32px; } h1 { font-size: 20px; } }
    </style>
</head>
<body>
    <div class="no-print">
        <div class="actions">
            <button class="btn" onclick="window.print()">Save as PDF / Print</button>
            @isset($excelUrl)
                <a class="btn alt" href="{{ $excelUrl }}">Download Excel</a>
            @endisset
            @isset($csvUrl)
                <a class="btn alt" href="{{ $csvUrl }}">Download CSV</a>
            @endisset
        </div>
        <p class="hint">On a phone: tap <strong>Save as PDF / Print</strong>, then choose <strong>Save as PDF</strong> as the destination.</p>
    </div>

    <h1>{{ $title }}</h1>
    <p class="sub">{{ $subtitle }}</p>

    <div class="scroll">
        <table>
            <thead>
                <tr>
                    @foreach ($headers as $h)
                        <th>{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($headers) }}">No data in this range.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <script>
        // Auto-open the print dialog on desktop only. Mobile browsers can hang
        // or silently ignore an onload print, so there we rely on the button.
        var isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
        if (!isMobile) {
            window.addEventListener('load', function () { setTimeout(window.print, 250); });
        }
    </script>
</body>
</html>
