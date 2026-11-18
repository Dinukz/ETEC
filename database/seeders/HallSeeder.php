<?php

namespace Database\Seeders;

use App\Models\Hall;
use Illuminate\Database\Seeder;

class HallSeeder extends Seeder
{
    public function run(): void
    {
        $halls = [
            ['name' => 'Lecture Hall 01', 'location' => 'Main Building - Floor 1', 'capacity' => 120],
            ['name' => 'Lecture Hall 02', 'location' => 'Main Building - Floor 1', 'capacity' => 80],
            ['name' => 'Lecture Hall 03', 'location' => 'Main Building - Floor 2', 'capacity' => 60],
            ['name' => 'Conference Room', 'location' => 'Admin Block', 'capacity' => 25],
        ];

        foreach ($halls as $hall) {
            Hall::firstOrCreate(['name' => $hall['name']], $hall + ['is_active' => true]);
        }
    }
}
