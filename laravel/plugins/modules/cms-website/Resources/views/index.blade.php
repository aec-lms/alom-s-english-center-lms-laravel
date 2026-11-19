@extends('layouts.admin')
@section('title', 'Website CMS, Categories & Homepage A-to-Z — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Website CMS, Categories &amp; Homepage A-to-Z Control</h1>
            <p class="text-xs text-slate-500 mt-0.5">Manage post categories, blog posts (with PDF &amp; PDF cover thumbnails), every homepage section, footer details, and contact inquiries.</p>
        </div>
        <a href="{{ route('home') }}" target="_blank" class="px-4 py-2 rounded-lg bg-white border border-slate-300 text-xs font-bold text-slate-700 hover:bg-slate-50">
            Preview Live Site &rarr;
        </a>
    </div>

    <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3" id="cms-admin-tabs">
        <button type="button" data-tab="posts" class="cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-[#0284C7] text-white">Posts &amp; PDFs ({{ $posts->count() }})</button>
        <button type="button" data-tab="categories" class="cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-white border border-slate-200 text-slate-700">CMS Categories ({{ $categories->count() }})</button>
        <button type="button" data-tab="homepage" class="cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-white border border-slate-200 text-slate-700">Homepage &amp; Footer A-to-Z</button>
        <button type="button" data-tab="inquiries" class="cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-white border border-slate-200 text-slate-700">Contact Inquiries ({{ $inquiries->count() }})</button>
    </div>

    <!-- TAB 1: BLOG POSTS WITH CATEGORY + PDF + PDF THUMBNAIL -->
    <section id="tab-posts" class="cms-tab-panel space-y-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Create New Post / Article (with PDF &amp; PDF Thumbnail)</h2>
            <form method="POST" action="{{ route('plugins.cms.posts.store') }}" enctype="multipart/form-data" class="grid sm:grid-cols-2 gap-3 text-xs">
                @csrf
                <input name="title" required placeholder="Post Title *" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="slug" placeholder="Custom URL Slug (optional)" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Select CMS Category</label>
                    <select name="category_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 bg-white">
                        <option value="">-- Use Custom Category Name Below --</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Category Name (Fallback)</label>
                    <input name="category" value="IELTS Preparation" placeholder="Category *" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <input name="author" value="Alom's English Center" placeholder="Author Name" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="seo_title" placeholder="SEO Meta Title (optional)" class="rounded-lg border border-slate-300 px-3 py-2">
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Post Cover Thumbnail</label>
                    <input type="file" name="thumbnail" accept="image/*" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                </div>
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Or Cover Thumbnail URL</label>
                    <input name="thumbnail_url" placeholder="/assets/images/..." class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Attach PDF Handout (.pdf)</label>
                    <input type="file" name="pdf_file" accept="application/pdf,.pdf" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                </div>
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Upload PDF Thumbnail Preview (Image)</label>
                    <input type="file" name="pdf_thumbnail" accept="image/*" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                </div>
                <input name="excerpt" placeholder="Short Summary / Excerpt" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
                <input name="seo_description" placeholder="SEO Meta Description (optional)" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
                <textarea name="content" rows="4" required placeholder="Full Post Content *" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2"></textarea>
                <div class="sm:col-span-2 flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 font-semibold text-slate-700"><input type="checkbox" name="is_published" value="1" checked> Publish immediately</label>
                    <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold">Publish Post</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">All Posts (Reusable in Course Creation)</h2>
            @forelse ($posts as $post)
                <div class="border border-slate-200 rounded-xl p-4 space-y-3 text-xs">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            @if ($post->pdf_thumbnail ?: $post->thumbnail_path)
                                <img src="{{ $post->pdf_thumbnail ?: $post->thumbnail_path }}" alt="{{ $post->title }}" class="w-16 h-12 rounded-lg object-cover border">
                            @endif
                            <div>
                                <div class="font-bold text-sm text-slate-900">{{ $post->title }}</div>
                                <div class="text-slate-500 font-mono">
                                    /learn#{{ $post->slug }} &middot; {{ $post->category }} &middot; {{ $post->is_published ? 'Published' : 'Draft' }}
                                    @if ($post->pdf_path) &middot; <span class="text-emerald-700 font-bold">PDF Attached</span> @endif
                                </div>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('plugins.cms.posts.destroy', $post) }}" onsubmit="return confirm('Delete this post?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold hover:bg-rose-50">Delete</button>
                        </form>
                    </div>
                    <details class="pt-2 border-t border-slate-100">
                        <summary class="cursor-pointer font-semibold text-[#0284C7]">Edit Post, PDF &amp; Thumbnails</summary>
                        <form method="POST" action="{{ route('plugins.cms.posts.update', $post) }}" enctype="multipart/form-data" class="mt-3 grid sm:grid-cols-2 gap-3">
                            @csrf @method('PUT')
                            <input name="title" required value="{{ $post->title }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="slug" required value="{{ $post->slug }}" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                            <select name="category_id" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <option value="">-- Category Link --</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}" @selected($post->category_id === $cat->id)>{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            <input name="category" value="{{ $post->category }}" placeholder="Category Label" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input type="file" name="thumbnail" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                            <input type="file" name="pdf_file" accept="application/pdf,.pdf" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                            <input type="file" name="pdf_thumbnail" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                            <input name="author" value="{{ $post->author }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="excerpt" value="{{ $post->excerpt }}" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
                            <input name="seo_title" value="{{ $post->seo_title }}" placeholder="SEO Meta Title" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="seo_description" value="{{ $post->seo_description }}" placeholder="SEO Meta Description" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="thumbnail_url" placeholder="Replace thumbnail with HTTPS URL or local path" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="pdf_url" placeholder="Replace PDF with HTTPS URL or local path" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="pdf_thumbnail_url" placeholder="Replace PDF cover with HTTPS URL or local path" class="rounded-lg border border-slate-300 px-3 py-2">
                            <textarea name="content" rows="3" required class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">{{ $post->content }}</textarea>
                            <div class="sm:col-span-2 flex items-center justify-between">
                                <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" name="is_published" value="1" @checked($post->is_published)> Published</label>
                                <button type="submit" class="px-4 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Update Post</button>
                            </div>
                        </form>
                    </details>
                </div>
            @empty
                <p class="text-xs text-slate-400">No blog posts created yet.</p>
            @endforelse
        </div>
    </section>

    <!-- TAB 2: CMS CATEGORIES CRUD -->
    <section id="tab-categories" class="cms-tab-panel hidden space-y-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Create CMS Category</h2>
            <form method="POST" action="{{ route('plugins.cms.categories.store') }}" class="grid sm:grid-cols-4 gap-3 text-xs">
                @csrf
                <input name="name" required placeholder="Category Name (e.g. IELTS Reading) *" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="slug" placeholder="Slug (optional)" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <input name="description" placeholder="Short Description" class="rounded-lg border border-slate-300 px-3 py-2">
                <button type="submit" class="rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold px-4 py-2">Create Category</button>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
            <h2 class="text-sm font-bold text-slate-900">CMS Categories</h2>
            @forelse ($categories as $cat)
                <div class="border border-slate-200 rounded-xl p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-sm text-slate-900">{{ $cat->name }}</span>
                            <span class="ml-2 font-mono text-slate-400">/{{ $cat->slug }}</span>
                        </div>
                        <form method="POST" action="{{ route('plugins.cms.categories.destroy', $cat) }}" onsubmit="return confirm('Delete category?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="px-3 py-1 rounded-lg border border-rose-200 text-rose-700 font-bold">Delete</button>
                        </form>
                    </div>
                    <form method="POST" action="{{ route('plugins.cms.categories.update', $cat) }}" class="grid sm:grid-cols-4 gap-2 pt-2 border-t border-slate-100">
                        @csrf @method('PUT')
                        <input name="name" required value="{{ $cat->name }}" class="rounded-lg border border-slate-300 px-3 py-1.5">
                        <input name="slug" required value="{{ $cat->slug }}" class="rounded-lg border border-slate-300 px-3 py-1.5 font-mono">
                        <input name="description" value="{{ $cat->description }}" class="rounded-lg border border-slate-300 px-3 py-1.5">
                        <div class="flex items-center justify-between gap-2">
                            <label class="inline-flex items-center gap-1 font-semibold"><input type="checkbox" name="is_active" value="1" @checked($cat->is_active)> Active</label>
                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Save</button>
                        </div>
                    </form>
                </div>
            @empty
                <p class="text-slate-400">No CMS categories added yet.</p>
            @endforelse
        </div>
    </section>

    <!-- TAB 3: FULL HOMEPAGE & FOOTER A-TO-Z CUSTOMIZER -->
    <section id="tab-homepage" class="cms-tab-panel hidden">
        <form method="POST" action="{{ route('plugins.cms.homepage.save') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-6 text-xs">
            @csrf
            <div class="space-y-3">
                <h2 class="text-sm font-bold text-slate-900">1. Section Visibility Toggles</h2>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 font-semibold text-slate-700 bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_slider" value="1" @checked($homepage['show_slider'] ?? true)> Hero Slider</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_batches" value="1" @checked($homepage['show_batches'] ?? true)> Ongoing Batches Card</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_stats" value="1" @checked($homepage['show_stats'] ?? true)> Live Stats Card</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_learning_paths" value="1" @checked($homepage['show_learning_paths'] ?? true)> 6 Learning Paths</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_digital_carousel" value="1" @checked($homepage['show_digital_carousel'] ?? true)> Digital Product Carousel</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_reviews" value="1" @checked($homepage['show_reviews'] ?? true)> Student Reviews Marquee</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_app_banner" value="1" @checked($homepage['show_app_banner'] ?? true)> iOS &amp; Android App Banner</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="footer_payment_strip" value="1" @checked($homepage['footer_payment_strip'] ?? true)> Footer SSLCommerz Strip</label>
                </div>
            </div>

            <div class="space-y-3 pt-4 border-t border-slate-100">
                <h2 class="text-sm font-bold text-slate-900">2. Dual Bento: Ongoing Batches &amp; Stats Controls</h2>
                <div class="grid sm:grid-cols-3 gap-3">
                    <label class="block font-semibold text-slate-700">Batches Badge <input name="batches_badge" value="{{ $homepage['batches_badge'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Batches Title <input name="batches_title" required value="{{ $homepage['batches_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Batches Subtitle <input name="batches_subtitle" required value="{{ $homepage['batches_subtitle'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>

                    <label class="block font-semibold text-slate-700">Track 1 Title <input name="batch_1_title" value="{{ $homepage['batch_1_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Track 1 Description <input name="batch_1_desc" value="{{ $homepage['batch_1_desc'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Track 1 Link URL <input name="batch_1_url" value="{{ $homepage['batch_1_url'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>

                    <label class="block font-semibold text-slate-700">Track 2 Title <input name="batch_2_title" value="{{ $homepage['batch_2_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Track 2 Description <input name="batch_2_desc" value="{{ $homepage['batch_2_desc'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Track 2 Link URL <input name="batch_2_url" value="{{ $homepage['batch_2_url'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                </div>

                <div class="grid sm:grid-cols-4 gap-3 pt-2">
                    <label class="block font-semibold text-slate-700">Stats Title <input name="stats_title" required value="{{ $homepage['stats_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Stats Subtitle <input name="stats_subtitle" required value="{{ $homepage['stats_subtitle'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Stats Source
                        <select name="stats_mode" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 bg-white font-normal">
                            <option value="auto" @selected(($homepage['stats_mode'] ?? 'auto') === 'auto')>Auto Live DB Count</option>
                            <option value="manual" @selected(($homepage['stats_mode'] ?? 'auto') === 'manual')>Manual Admin Override</option>
                        </select>
                    </label>
                    <label class="block font-semibold text-slate-700">Students Override <input type="number" min="0" name="stats_students_override" value="{{ $homepage['stats_students_override'] ?? 0 }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                    <label class="block font-semibold text-slate-700">Courses Override <input type="number" min="0" name="stats_courses_override" value="{{ $homepage['stats_courses_override'] ?? 0 }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                    <label class="block font-semibold text-slate-700">Teachers Override <input type="number" min="0" name="stats_teachers_override" value="{{ $homepage['stats_teachers_override'] ?? 0 }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                    <label class="block font-semibold text-slate-700">Lessons Override <input type="number" min="0" name="stats_lessons_override" value="{{ $homepage['stats_lessons_override'] ?? 0 }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                </div>
            </div>

            <div class="space-y-3 pt-4 border-t border-slate-100">
                <h2 class="text-sm font-bold text-slate-900">3. Choose Your Learning Path (All 6 Pastel Cards)</h2>
                <div class="grid sm:grid-cols-2 gap-3">
                    <label class="block font-semibold text-slate-700">Section Heading <input name="paths_title" required value="{{ $homepage['paths_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Section Subtitle <input name="paths_subtitle" required value="{{ $homepage['paths_subtitle'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                </div>
                <div class="grid sm:grid-cols-2 gap-3">
                    @foreach ($homepage['learning_paths'] as $idx => $lp)
                        <div class="border border-slate-200 rounded-xl p-3 space-y-2 bg-slate-50/70">
                            <div class="flex items-center justify-between font-bold text-slate-800">
                                <span>Path Card #{{ $idx + 1 }}</span>
                                <label class="inline-flex items-center gap-1"><input type="checkbox" name="paths[{{ $idx }}][enabled]" value="1" @checked($lp['enabled'] ?? true)> Active</label>
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <input name="paths[{{ $idx }}][abbr]" value="{{ $lp['abbr'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 font-mono" placeholder="Code">
                                <input name="paths[{{ $idx }}][title]" value="{{ $lp['title'] }}" class="col-span-2 rounded-lg border border-slate-300 px-2 py-1.5" placeholder="Card Title">
                            </div>
                            <input name="paths[{{ $idx }}][desc]" value="{{ $lp['desc'] }}" class="w-full rounded-lg border border-slate-300 px-2 py-1.5" placeholder="Description">
                            <input name="paths[{{ $idx }}][url]" value="{{ $lp['url'] }}" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono" placeholder="/courses">
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="space-y-3 pt-4 border-t border-slate-100">
                <h2 class="text-sm font-bold text-slate-900">4. Digital Product Carousel &amp; Reviews Section</h2>
                <div class="grid sm:grid-cols-3 gap-3">
                    <label class="block font-semibold text-slate-700">Carousel Title <input name="carousel_title" value="{{ $homepage['carousel_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Carousel CTA Button <input name="carousel_cta_text" value="{{ $homepage['carousel_cta_text'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Carousel Subtitle <input name="carousel_subtitle" value="{{ $homepage['carousel_subtitle'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Reviews Heading <input name="reviews_title" required value="{{ $homepage['reviews_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="sm:col-span-2 block font-semibold text-slate-700">Reviews Subtitle <input name="reviews_subtitle" required value="{{ $homepage['reviews_subtitle'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                </div>
            </div>

            <div class="space-y-3 pt-4 border-t border-slate-100">
                <h2 class="text-sm font-bold text-slate-900">5. Mobile App Banner (Apple iOS &amp; Android) &amp; Footer Control</h2>
                <div class="grid sm:grid-cols-2 gap-3">
                    <label class="block font-semibold text-slate-700">App Banner Eyebrow <input name="app_banner_eyebrow" value="{{ $homepage['app_banner_eyebrow'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">App Banner Headline <input name="app_banner_title" value="{{ $homepage['app_banner_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="sm:col-span-2 block font-semibold text-slate-700">App Banner Subtitle <input name="app_banner_subtitle" value="{{ $homepage['app_banner_subtitle'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Apple App Store URL (iOS) <input name="app_ios_url" value="{{ $homepage['app_ios_url'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                    <label class="block font-semibold text-slate-700">Google Play Store URL (Android) <input name="app_android_url" value="{{ $homepage['app_android_url'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                    <label class="sm:col-span-2 block font-semibold text-slate-700">Footer Mission Description <textarea name="footer_description" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">{{ $homepage['footer_description'] }}</textarea></label>
                    <label class="block font-semibold text-slate-700">Trade License No <input name="footer_license_no" value="{{ $homepage['footer_license_no'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                    <label class="block font-semibold text-slate-700">Campus Address <input name="footer_address" value="{{ $homepage['footer_address'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></label>
                    <label class="block font-semibold text-slate-700">Hotline Phone <input name="footer_phone" value="{{ $homepage['footer_phone'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                    <label class="block font-semibold text-slate-700">Official Email <input type="email" name="footer_email" value="{{ $homepage['footer_email'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                    <label class="block font-semibold text-slate-700">YouTube Channel URL <input name="footer_youtube_url" value="{{ $homepage['footer_youtube_url'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                    <label class="block font-semibold text-slate-700">Facebook Page URL <input name="footer_facebook_url" value="{{ $homepage['footer_facebook_url'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal font-mono"></label>
                </div>
            </div>

            <div class="text-right pt-2">
                <button type="submit" class="px-6 py-2.5 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold shadow-sm">Save A-to-Z Homepage &amp; Footer</button>
            </div>
        </form>
    </section>

    <!-- TAB 4: CONTACT INQUIRIES -->
    <section id="tab-inquiries" class="cms-tab-panel hidden bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
        <h2 class="text-sm font-bold text-slate-900">Contact Inquiries</h2>
        @forelse ($inquiries as $inq)
            <div class="border border-slate-200 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <div class="font-bold text-slate-900">{{ $inq->subject }} &mdash; {{ $inq->name }} ({{ $inq->email }})</div>
                    <p class="text-slate-600 mt-1">{{ $inq->message }}</p>
                </div>
                <form method="POST" action="{{ route('plugins.cms.inquiries.status', $inq->id) }}" class="flex items-center gap-2">
                    @csrf
                    <select name="status" class="rounded-lg border border-slate-300 px-2 py-1 bg-white">
                        @foreach (['new', 'in_progress', 'resolved'] as $st)
                            <option value="{{ $st }}" @selected($inq->status === $st)>{{ strtoupper($st) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-3 py-1 rounded-lg bg-slate-900 text-white font-bold">Save</button>
                </form>
            </div>
        @empty
            <p class="text-slate-400">No contact inquiries submitted yet.</p>
        @endforelse
    </section>
</div>
<script>
(() => {
    const buttons = document.querySelectorAll('.cms-tab-btn');
    const panels = document.querySelectorAll('.cms-tab-panel');
    buttons.forEach(btn => btn.addEventListener('click', () => {
        const t = btn.getAttribute('data-tab');
        buttons.forEach(b => b.className = 'cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-white border border-slate-200 text-slate-700');
        btn.className = 'cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-[#0284C7] text-white';
        panels.forEach(p => p.classList.toggle('hidden', p.id !== 'tab-' + t));
    }));
})();
</script>
@includeIf('media-library::partials.editor-tools')

@endsection
