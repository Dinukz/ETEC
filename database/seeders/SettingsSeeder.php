<?php

namespace Database\Seeders;

use App\Models\CommissionSetting;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // group => [key => [value, type]]
            'general' => [
                'site_name' => ['ETEC', 'string'],
                'site_tagline' => ['Empowering Education & Careers', 'string'],
                'contact_email' => ['info@etec.lk', 'string'],
                'contact_phone' => ['+94 11 234 5678', 'string'],
                'contact_address' => ['Colombo, Sri Lanka', 'string'],
                'default_currency' => ['LKR', 'string'],
            ],
            'theme' => [
                'default_theme' => ['light', 'string'],
                'primary_color' => ['#1D4ED8', 'string'],   // blue
                'accent_color' => ['#FACC15', 'string'],     // yellow
            ],
            'commission' => [
                'course_price' => ['28000', 'integer'],
                'half_payment_amount' => ['18000', 'integer'],
                'commission_per_student' => ['2500', 'integer'],
                'max_commission' => ['15000', 'integer'],
                'group_size' => ['6', 'integer'],
            ],
            'payment' => [
                'manual_verification_required' => ['1', 'boolean'],
                'bank_name' => ['Bank of Ceylon', 'string'],
                'bank_account_name' => ['ETEC (Pvt) Ltd', 'string'],
                'bank_account_number' => ['0000000000', 'string'],
            ],
        ];

        foreach ($settings as $group => $entries) {
            foreach ($entries as $key => [$value, $type]) {
                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'type' => $type, 'group' => $group, 'is_public' => $group !== 'payment'],
                );
            }
        }

        // Versioned commission configuration used by the commission engine.
        CommissionSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'course_price' => 28000,
                'commission_per_student' => 2500,
                'max_commission' => 15000,
                'group_size' => 6,
                'first_tier_students' => 3,
                'first_tier_amount' => 7500,
                'second_tier_students' => 3,
                'second_tier_amount' => 7500,
                'is_active' => true,
                'effective_from' => now(),
            ],
        );
    }
}
