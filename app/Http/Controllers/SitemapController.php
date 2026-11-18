<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\Course;
use Illuminate\Http\Response;

/**
 * /sitemap.xml — every public page, for search engines (2026-09-30).
 *
 * Asked for with the Google report: *"search bar eke etecbusiness gahuwama hari
 * ena widiyta karnn puluwnd"*. A sitemap does not make Google rank the site —
 * nothing on the site can — but it is what Search Console is given so every
 * public page is known about, rather than only the ones a crawler happens to
 * find by following links.
 *
 * ⚠️ PUBLIC PAGES ONLY. Nothing behind a login is listed: those pages answer a
 * crawler with a redirect to /login, and naming them would only teach Google
 * that the site is full of pages it cannot open.
 *
 * Built per request from route() — so the addresses are whatever the site is
 * actually served at (no /public once the root .htaccess is in place) — and
 * from published courses and posts only.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        // Static public pages, most important first.
        $urls = collect([
            ['loc' => route('home'), 'priority' => '1.0', 'changefreq' => 'daily'],
            ['loc' => route('courses.index'), 'priority' => '0.9', 'changefreq' => 'daily'],
            ['loc' => route('about'), 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['loc' => route('consultant-program'), 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['loc' => route('services'), 'priority' => '0.7', 'changefreq' => 'monthly'],
            ['loc' => route('contact'), 'priority' => '0.7', 'changefreq' => 'monthly'],
            ['loc' => route('blog.index'), 'priority' => '0.6', 'changefreq' => 'weekly'],
            ['loc' => route('faq'), 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['loc' => route('careers'), 'priority' => '0.4', 'changefreq' => 'monthly'],
            ['loc' => route('certificates.verify'), 'priority' => '0.3', 'changefreq' => 'yearly'],
        ]);

        foreach (Course::published()->get(['id', 'slug', 'updated_at']) as $course) {
            $urls->push([
                'loc' => route('courses.show', $course),
                'lastmod' => $course->updated_at?->toAtomString(),
                'priority' => '0.8',
                'changefreq' => 'weekly',
            ]);
        }

        foreach (Blog::published()->get(['id', 'slug', 'updated_at', 'published_at']) as $post) {
            $urls->push([
                'loc' => route('blog.show', $post),
                'lastmod' => ($post->updated_at ?? $post->published_at)?->toAtomString(),
                'priority' => '0.5',
                'changefreq' => 'monthly',
            ]);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n"
                .'    <loc>'.e($url['loc'])."</loc>\n"
                .(! empty($url['lastmod']) ? '    <lastmod>'.e($url['lastmod'])."</lastmod>\n" : '')
                .'    <changefreq>'.$url['changefreq']."</changefreq>\n"
                .'    <priority>'.$url['priority']."</priority>\n"
                ."  </url>\n";
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
