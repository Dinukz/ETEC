<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Order matters: roles → settings → users (need roles) → courses/cms (need users).
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
            UserSeeder::class,
            CourseSeeder::class,
            CmsSeeder::class,
            HallSeeder::class,
        ]);
    }
}
