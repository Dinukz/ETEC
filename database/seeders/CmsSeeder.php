<?php

namespace Database\Seeders;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Database\Seeder;

class CmsSeeder extends Seeder
{
    public function run(): void
    {
        $faqs = [
            ['How do I enrol in a course?', 'Register an account, choose your course and complete the payment. Once our team verifies your payment you get instant LMS access.'],
            ['How does payment verification work?', 'Payments are manually verified by our accounts team, usually within 24 hours of submission.'],
            ['How can I become a Consultant?', 'Consultants are upgraded from students by our administrators. You cannot become a consultant automatically.'],
            ['How is commission calculated?', 'Consultants earn LKR 7,500 for the first 3 students and another LKR 7,500 for the next 3, up to LKR 15,000 per group of 6.'],
        ];

        foreach ($faqs as $i => [$q, $a]) {
            Faq::query()->firstOrCreate(['question' => $q], ['answer' => $a, 'sort_order' => $i, 'is_active' => true]);
        }

        $testimonials = [
            ['Nimal Perera', 'Graduate', 'ETEC transformed my career. The mobile LMS meant I could study anytime.', 5],
            ['Kumari Silva', 'Consultant', 'The commission system is transparent and I can track every group in real time.', 5],
        ];

        foreach ($testimonials as $i => [$name, $role, $content, $rating]) {
            Testimonial::query()->firstOrCreate(
                ['name' => $name],
                ['designation' => $role, 'content' => $content, 'rating' => $rating, 'is_featured' => true, 'status' => 'approved', 'sort_order' => $i],
            );
        }

        Page::query()->firstOrCreate(
            ['slug' => 'about-us'],
            [
                'title' => 'About Us',
                'content' => '<p>ETEC is a Sri Lankan education platform combining a professional LMS with a consultant-driven growth model.</p>',
                'status' => 'published',
            ],
        );

        if ($author = User::role('admin')->first()) {
            Blog::query()->firstOrCreate(
                ['slug' => 'welcome-to-etec'],
                [
                    'author_id' => $author->id,
                    'title' => 'Welcome to ETEC',
                    'excerpt' => 'Our new learning platform is live.',
                    'content' => '<p>We are excited to launch the ETEC learning and consultant platform.</p>',
                    'category' => 'News',
                    'status' => 'published',
                    'is_featured' => true,
                    'published_at' => now(),
                ],
            );
        }
    }
}
