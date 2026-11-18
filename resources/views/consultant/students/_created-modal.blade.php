@props(['created'])

{{--
    Shown once, right after a student is registered. The consultant needs the
    Pay ID to pass on to the student, so it doubles as a receipt they can save
    as an image and send.

    The image is painted onto a <canvas> here rather than screenshotting the DOM
    with a library: no new npm dependency to build and upload, and the logo is
    served from our own origin so the canvas is never tainted and toDataURL()
    keeps working.
--}}
<div x-data="studentCreatedReceipt({
        name: @js($created['name'] ?? ''),
        payId: @js($created['payment_code'] ?? ''),
        course: @js($created['course'] ?? ''),
        expiresOn: @js($created['expires_on'] ?? ''),
        logo: @js(asset('images/etec-logo.jpeg')),
    })" x-cloak x-show="open"
    class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/50 p-4">

    <div class="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-2xl" @click.outside="open = false">
        <img src="{{ asset('images/etec-logo.jpeg') }}" alt="{{ config('app.name') }}" class="mx-auto h-20 w-auto rounded-lg">

        <p class="mt-5 text-lg font-extrabold text-brand-600">The Home Of Future Entrepreneurs</p>

        <h3 class="mt-5 text-2xl font-extrabold text-slate-900">Student successfully created</h3>

        <div class="mt-6 rounded-xl bg-slate-50 p-5 text-left">
            <p class="text-sm text-slate-500">Student Name: <span class="font-bold text-slate-900" x-text="data.name"></span></p>
            <p class="mt-2 text-sm text-slate-500">Pay ID: <span class="font-bold text-slate-900" x-text="data.payId"></span></p>
            <p class="mt-2 text-sm text-slate-500">Course: <span class="font-bold text-slate-900" x-text="data.course"></span></p>

            <div class="mt-4 border-t border-slate-200 pt-4">
                <p class="text-sm text-slate-600">
                    The student registration will expire if payment is not made by the student on or before
                    <span class="font-bold text-slate-900" x-text="data.expiresOn"></span>
                </p>
            </div>
        </div>

        <div class="mt-6 flex flex-col gap-3 sm:flex-row">
            <button type="button" class="btn-secondary w-full" @click="saveImage()">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2m-10-4l4 4m0 0l4-4m-4 4V4"/></svg>
                <span x-text="saving ? 'Preparing…' : 'Save as image'"></span>
            </button>
            <button type="button" class="btn-primary w-full" @click="open = false">OK</button>
        </div>
    </div>
</div>

<script>
    function studentCreatedReceipt(data) {
        return {
            open: true,
            saving: false,
            data: data,

            /** Split text into lines that fit maxWidth under the context's current font. */
            wrap(ctx, text, maxWidth) {
                const lines = [];
                let line = '';

                for (const word of String(text).split(' ')) {
                    const candidate = line ? line + ' ' + word : word;

                    if (line && ctx.measureText(candidate).width > maxWidth) {
                        lines.push(line);
                        line = word;
                    } else {
                        line = candidate;
                    }
                }

                if (line) lines.push(line);

                return lines;
            },

            saveImage() {
                this.saving = true;

                const logo = new Image();
                // Same origin, so no crossOrigin attribute is needed — and setting
                // one would force a CORS request this host does not answer.
                logo.onload = () => this.paint(logo);
                logo.onerror = () => this.paint(null);
                logo.src = this.data.logo;
            },

            paint(logo) {
                const SCALE = 2;
                const W = 900;
                const PAD = 70;
                const inner = W - (PAD * 2);

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');

                // --- Pass 1: measure, so the canvas is exactly as tall as needed.
                const logoH = logo ? Math.round(300 * (logo.naturalHeight / logo.naturalWidth)) : 0;

                ctx.font = '400 26px "Plus Jakarta Sans", Arial, sans-serif';
                const expiryLines = this.wrap(
                    ctx,
                    'The student registration will expire if payment is not made by the student on or before ' + this.data.expiresOn,
                    inner - 72,
                );

                const boxH = 60 + (3 * 46) + 40 + (expiryLines.length * 38) + 20;
                const H = PAD + (logo ? logoH + 44 : 0) + 46 + 78 + boxH + PAD;

                canvas.width = W * SCALE;
                canvas.height = H * SCALE;
                ctx.scale(SCALE, SCALE);

                // --- Pass 2: draw.
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, W, H);

                let y = PAD;

                if (logo) {
                    ctx.drawImage(logo, (W - 300) / 2, y, 300, logoH);
                    y += logoH + 44;
                }

                ctx.textAlign = 'center';
                ctx.fillStyle = '#1d40f5'; // --color-brand-600, matching the on-screen card
                ctx.font = '800 28px "Plus Jakarta Sans", Arial, sans-serif';
                ctx.fillText('The Home Of Future Entrepreneurs', W / 2, y + 20);
                y += 46;

                ctx.fillStyle = '#0f172a';
                ctx.font = '800 44px "Plus Jakarta Sans", Arial, sans-serif';
                ctx.fillText('Student successfully created', W / 2, y + 44);
                y += 78;

                // Details box
                ctx.fillStyle = '#f8fafc';
                if (ctx.roundRect) {
                    ctx.beginPath();
                    ctx.roundRect(PAD, y, inner, boxH, 20);
                    ctx.fill();
                } else {
                    ctx.fillRect(PAD, y, inner, boxH);
                }

                ctx.textAlign = 'left';
                const x = PAD + 36;
                let ly = y + 60;

                for (const row of [['Student Name', this.data.name], ['Pay ID', this.data.payId], ['Course', this.data.course]]) {
                    ctx.fillStyle = '#64748b';
                    ctx.font = '400 26px "Plus Jakarta Sans", Arial, sans-serif';
                    const label = row[0] + ': ';
                    ctx.fillText(label, x, ly);
                    // Measure while the LABEL's own font is still active — the
                    // bold face below is wider and would push the value along.
                    const valueX = x + ctx.measureText(label).width;

                    ctx.fillStyle = '#0f172a';
                    ctx.font = '700 26px "Plus Jakarta Sans", Arial, sans-serif';
                    ctx.fillText(row[1], valueX, ly);

                    ly += 46;
                }

                ly += 8;
                ctx.strokeStyle = '#e2e8f0';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(x, ly);
                ctx.lineTo(PAD + inner - 36, ly);
                ctx.stroke();
                ly += 40;

                ctx.fillStyle = '#334155';
                ctx.font = '400 26px "Plus Jakarta Sans", Arial, sans-serif';
                for (const line of expiryLines) {
                    ctx.fillText(line, x, ly);
                    ly += 38;
                }

                const link = document.createElement('a');
                link.download = (this.data.payId || 'student') + '.png';
                link.href = canvas.toDataURL('image/png');
                link.click();

                this.saving = false;
            },
        };
    }
</script>
