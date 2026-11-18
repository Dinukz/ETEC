<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CourseSeeder extends Seeder
{
    public function run(): void
    {
        $lecturer = User::role('lecturer')->first();

        $course = Course::query()->firstOrCreate(
            ['slug' => 'professional-diploma-in-business-management'],
            [
                'title' => 'Professional Diploma in Business Management',
                'subtitle' => 'Flagship ETEC programme',
                'description' => 'A comprehensive, career-focused diploma covering management, finance, marketing and entrepreneurship.',
                'price' => 28000,
                'currency' => 'LKR',
                'level' => 'beginner',
                'duration_text' => '6 months',
                'category' => 'Business',
                'lecturer_id' => $lecturer?->id,
                'status' => 'published',
                'is_featured' => true,
                'published_at' => now(),
                // Without these, the Add Student form's Language/Class Type/
                // Course Type auto-fill has nothing to fill in from.
                'default_language' => 'Sinhala',
                'default_class_type' => 'Online',
                'default_course_type' => 'Group',
            ],
        );

        $modules = [
            'Foundations of Management' => ['Introduction to Management', 'Organizational Behaviour'],
            'Finance & Accounting' => ['Financial Basics', 'Reading Financial Statements'],
            'Marketing Essentials' => ['Marketing Fundamentals', 'Digital Marketing'],
        ];

        $moduleOrder = 0;
        foreach ($modules as $moduleTitle => $lessonTitles) {
            $module = CourseModule::query()->firstOrCreate(
                ['course_id' => $course->id, 'title' => $moduleTitle],
                ['sort_order' => $moduleOrder++],
            );

            $lessonOrder = 0;
            foreach ($lessonTitles as $lessonTitle) {
                Lesson::query()->firstOrCreate(
                    ['course_id' => $course->id, 'slug' => Str::slug($lessonTitle)],
                    [
                        'module_id' => $module->id,
                        'title' => $lessonTitle,
                        'content' => "Lesson material for {$lessonTitle}.",
                        'video_provider' => 'youtube',
                        'duration_minutes' => 20,
                        'sort_order' => $lessonOrder++,
                        'is_preview' => $lessonOrder === 1,
                        'status' => 'published',
                    ],
                );
            }
        }
    }
}
