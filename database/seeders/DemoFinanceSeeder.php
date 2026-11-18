<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo-only: creates pending payments (some referred by the seeded consultant)
 * so the payment/commission/group modules have data to exercise.
 *
 * NOT registered in DatabaseSeeder — run explicitly:
 *   php artisan db:seed --class=DemoFinanceSeeder
 */
class DemoFinanceSeeder extends Seeder
{
    public function run(): void
    {
        $course = Course::first();
        $consultant = User::whereHas('consultant')->first()?->consultant;

        if (! $course) {
            $this->command?->warn('No course found — skipping demo finance data.');
            return;
        }

        // Create several demo students, each with a pending payment.
        for ($i = 1; $i <= 8; $i++) {
            $user = User::firstOrCreate(
                ['email' => "demostudent{$i}@etec.lk"],
                ['name' => "Demo Student {$i}", 'password' => bcrypt('password'), 'status' => 'active', 'email_verified_at' => now()],
            );

            if (! $user->hasRole('student')) {
                $user->assignRole('student');
                \App\Models\Student::firstOrCreate(['user_id' => $user->id], [
                    'student_code' => 'STU-D'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'status' => 'active',
                    'enrolled_at' => now(),
                ]);
            }

            $enrollment = Enrollment::firstOrCreate(
                ['user_id' => $user->id, 'course_id' => $course->id],
                ['status' => 'pending', 'progress_percentage' => 0, 'enrolled_at' => now()],
            );

            Payment::firstOrCreate(
                ['payment_code' => "PAY-DEMO-{$i}"],
                [
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'enrollment_id' => $enrollment->id,
                    // First 6 are referred by the consultant, rest are direct signups.
                    'consultant_id' => $i <= 6 ? $consultant?->id : null,
                    'amount' => $course->price ?: 28000,
                    'currency' => 'LKR',
                    'method' => 'bank_transfer',
                    'reference_number' => 'REF'.strtoupper(Str::random(8)),
                    'status' => 'pending',
                    'paid_at' => now()->subDays(rand(0, 5)),
                ],
            );
        }

        $this->command?->info('Demo finance data seeded: 8 pending payments (6 referred).');
    }
}
