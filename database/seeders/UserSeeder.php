<?php

namespace Database\Seeders;

use App\Models\Consultant;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['super_admin', 'ETEC Super Admin', 'superadmin@etec.lk'],
            ['admin', 'ETEC Admin', 'admin@etec.lk'],
            ['accountant', 'ETEC Accountant', 'accountant@etec.lk'],
            ['lecturer', 'Sample Lecturer', 'lecturer@etec.lk'],
            ['consultant', 'Sample Consultant', 'consultant@etec.lk'],
            ['student', 'Sample Student', 'student@etec.lk'],
        ];

        foreach ($accounts as [$role, $name, $email]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'status' => 'active',
                    'theme' => 'light',
                ],
            );

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            if ($role === 'consultant') {
                Consultant::query()->firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'consultant_code' => 'CON-'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
                        'referral_code' => strtoupper(Str::random(8)),
                        'status' => 'active',
                        'approved_at' => now(),
                    ],
                );
            }

            if ($role === 'student') {
                Student::query()->firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'student_code' => 'STU-'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
                        'status' => 'active',
                        'enrolled_at' => now(),
                    ],
                );
            }
        }
    }
}
