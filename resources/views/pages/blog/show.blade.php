<x-layouts.public :title="$blog->title" :metaDescription="$blog->meta_description ?? $blog->excerpt">
    <article>
        {{-- Header --}}
        <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="container-app max-w-3xl py-12 lg:py-16">
                <nav class="mb-5 flex flex-wrap items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                    <a href="{{ url('/') }}" class="hover:text-brand-600 dark:hover:text-brand-400">Home</a>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    <a href="{{ route('blog.index') }}" class="hover:text-brand-600 dark:hover:text-brand-400">Blog</a>
                </nav>
                @if ($blog->category)<span class="badge bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">{{ $blog->category }}</span>@endif
                <h1 class="mt-4 text-3xl font-extrabold leading-tight tracking-tight text-slate-900 dark:text-white sm:text-4xl lg:text-5xl">{{ $blog->title }}</h1>
                <div class="mt-6 flex flex-wrap items-center gap-4 text-sm text-slate-500 dark:text-slate-400">
                    @if ($blog->author)
                        <span class="inline-flex items-center gap-2">
                            <span class="grid h-9 w-9 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-sm font-bold text-white">{{ strtoupper(substr($blog->author->name, 0, 1)) }}</span>
                            {{ $blog->author->name }}
                        </span>
                    @endif
                    <span>·</span>
                    <span>{{ optional($blog->published_at)->format('F d, Y') }}</span>
                    <span>·</span>
                    <span>{{ number_format($blog->views) }} views</span>
                </div>
            </div>
        </header>

        {{-- Featured image --}}
        @if ($blog->featured_image)
            <div class="container-app max-w-4xl">
                <img src="{{ $blog->featured_image_url }}" alt="{{ $blog->title }}" class="-mt-8 aspect-video w-full rounded-2xl object-cover shadow-lg">
            </div>
        @endif

        {{-- Body --}}
        <div class="container-app max-w-3xl py-12">
            <div class="prose prose-lg prose-slate max-w-none dark:prose-invert prose-headings:font-bold prose-a:text-brand-600 prose-img:rounded-xl">
                {!! $blog->content !!}
            </div>

            {{-- Tags --}}
            @if (! empty($blog->tags))
                <div class="mt-10 flex flex-wrap gap-2 border-t border-slate-200 pt-8 dark:border-slate-800">
                    @foreach ($blog->tags as $tag)
                        <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">#{{ $tag }}</span>
                    @endforeach
                </div>
            @endif

            {{-- Share --}}
            <div class="mt-8 flex items-center gap-3">
                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Share:</span>
                @php $shareUrl = urlencode(route('blog.show', $blog->slug)); $shareText = urlencode($blog->title); @endphp
                <a href="https://twitter.com/intent/tweet?url={{ $shareUrl }}&text={{ $shareText }}" target="_blank" rel="noopener" class="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M23 3a10.9 10.9 0 01-3.14 1.53 4.48 4.48 0 00-7.86 3v1A10.66 10.66 0 013 4s-4 9 5 13a11.64 11.64 0 01-7 2c9 5 20 0 20-11.5a4.5 4.5 0 00-.08-.83A7.72 7.72 0 0023 3z"/></svg></a>
                <a href="https://www.facebook.com/sharer/sharer.php?u={{ $shareUrl }}" target="_blank" rel="noopener" class="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg></a>
                <a href="https://api.whatsapp.com/send?text={{ $shareText }}%20{{ $shareUrl }}" target="_blank" rel="noopener" class="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800"><svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c0-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413"/></svg></a>
            </div>
        </div>

        {{-- Related --}}
        @if ($related->isNotEmpty())
            <section class="bg-white py-16 dark:bg-slate-900">
                <div class="container-app">
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Related articles</h2>
                    <div class="mt-8 grid gap-6 md:grid-cols-3">
                        @foreach ($related as $post)
                            <x-blog-card :post="$post" />
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    </article>
</x-layouts.public>
