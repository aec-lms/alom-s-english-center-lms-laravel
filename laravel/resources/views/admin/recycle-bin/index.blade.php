@extends('layouts.admin')
@section('title', 'Recycle Bin — AEC LMS')
@section('content')
<div class="max-w-6xl space-y-5">
    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Admin Recycle Bin</h1>
        <p class="text-xs text-slate-500 mt-1">Restore deleted records or permanently remove selected records after confirmation. Linked records may prevent permanent deletion.</p>
    </div>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Students</h2>
            <span class="text-xs text-slate-500">{{ $students->total() }} item(s)</span>
        </div>
        @forelse ($students as $student)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $student->user?->name ?? 'Deleted account' }}</div>
                    <div class="text-xs text-slate-500">{{ $student->student_code }} · {{ $student->user?->email ?? 'No linked email' }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $student->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.students.restore', $student->id) }}" onsubmit="return confirm('Restore this student and their previous account access?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'students', 'recordId' => $student->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted students.</p>
        @endforelse
        <div>{{ $students->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Instructors</h2>
            <span class="text-xs text-slate-500">{{ $teachers->total() }} item(s)</span>
        </div>
        @forelse ($teachers as $teacher)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $teacher->user?->name ?? 'Deleted account' }}</div>
                    <div class="text-xs text-slate-500">{{ $teacher->employee_code }} · {{ $teacher->user?->email ?? 'No linked email' }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $teacher->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.teachers.restore', $teacher->id) }}" onsubmit="return confirm('Restore this instructor and their previous account access?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'teachers', 'recordId' => $teacher->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted instructors.</p>
        @endforelse
        <div>{{ $teachers->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Courses</h2>
            <span class="text-xs text-slate-500">{{ $courses->total() }} item(s)</span>
        </div>
        @forelse ($courses as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->title }}</div>
                    <div class="text-xs text-slate-500">{{ $record->code }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.courses.restore', $record->id) }}" onsubmit="return confirm('Restore this course record?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'courses', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted courses.</p>
        @endforelse
        <div>{{ $courses->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Exams</h2>
            <span class="text-xs text-slate-500">{{ $exams->total() }} item(s)</span>
        </div>
        @forelse ($exams as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->title }}</div>
                    
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.exams.restore', $record->id) }}" onsubmit="return confirm('Restore this exam record?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'exams', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted exams.</p>
        @endforelse
        <div>{{ $exams->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Study Batches</h2>
            <span class="text-xs text-slate-500">{{ $batches->total() }} item(s)</span>
        </div>
        @forelse ($batches as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->name }}</div>
                    <div class="text-xs text-slate-500">{{ $record->batch_code }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.batches.restore', $record->id) }}" onsubmit="return confirm('Restore this study batch record?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'batches', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted study batches.</p>
        @endforelse
        <div>{{ $batches->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Academic Classes</h2>
            <span class="text-xs text-slate-500">{{ $classes->total() }} item(s)</span>
        </div>
        @forelse ($classes as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->name }}</div>
                    <div class="text-xs text-slate-500">{{ $record->code }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.classes.restore', $record->id) }}" onsubmit="return confirm('Restore this academic class record?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'classes', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted academic classes.</p>
        @endforelse
        <div>{{ $classes->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Course Categories</h2>
            <span class="text-xs text-slate-500">{{ $courseCategories->total() }} item(s)</span>
        </div>
        @forelse ($courseCategories as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->name }}</div>
                    
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.course-categories.restore', $record->id) }}" onsubmit="return confirm('Restore this course category record?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'course-categories', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted course categories.</p>
        @endforelse
        <div>{{ $courseCategories->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Live Classes</h2>
            <span class="text-xs text-slate-500">{{ $liveClasses->total() }} item(s)</span>
        </div>
        @forelse ($liveClasses as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->title }}</div>
                    <div class="text-xs text-slate-500">{{ $record->course?->title ?? 'Course unavailable' }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.live-classes.restore', $record->id) }}" onsubmit="return confirm('Restore this live class record?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'live-classes', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted live classes.</p>
        @endforelse
        <div>{{ $liveClasses->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Boost Campaigns</h2>
            <span class="text-xs text-slate-500">{{ $boostCampaigns->total() }} item(s)</span>
        </div>
        @forelse ($boostCampaigns as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->campaign_name }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.boost-campaigns.restore', $record->id) }}" onsubmit="return confirm('Restore this boost campaign?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'boost-campaigns', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted boost campaigns.</p>
        @endforelse
        <div>{{ $boostCampaigns->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Branches</h2>
            <span class="text-xs text-slate-500">{{ $branches->total() }} item(s)</span>
        </div>
        @forelse ($branches as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->name }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.branches.restore', $record->id) }}" onsubmit="return confirm('Restore this branch?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'branches', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted branches.</p>
        @endforelse
        <div>{{ $branches->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Carousel Items</h2>
            <span class="text-xs text-slate-500">{{ $carouselItems->total() }} item(s)</span>
        </div>
        @forelse ($carouselItems as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->title }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.carousel-items.restore', $record->id) }}" onsubmit="return confirm('Restore this carousel item?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'carousel-items', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted carousel items.</p>
        @endforelse
        <div>{{ $carouselItems->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted CMS Categories</h2>
            <span class="text-xs text-slate-500">{{ $cmsCategories->total() }} item(s)</span>
        </div>
        @forelse ($cmsCategories as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->name }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.cms-categories.restore', $record->id) }}" onsubmit="return confirm('Restore this CMS category?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'cms-categories', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted cms categories.</p>
        @endforelse
        <div>{{ $cmsCategories->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted CMS Posts</h2>
            <span class="text-xs text-slate-500">{{ $cmsPosts->total() }} item(s)</span>
        </div>
        @forelse ($cmsPosts as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->title }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.cms-posts.restore', $record->id) }}" onsubmit="return confirm('Restore this CMS post?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'cms-posts', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted cms posts.</p>
        @endforelse
        <div>{{ $cmsPosts->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Builder Pages</h2>
            <span class="text-xs text-slate-500">{{ $builderPages->total() }} item(s)</span>
        </div>
        @forelse ($builderPages as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->title }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.builder-pages.restore', $record->id) }}" onsubmit="return confirm('Restore this page?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'builder-pages', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted builder pages.</p>
        @endforelse
        <div>{{ $builderPages->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Hero Sliders</h2>
            <span class="text-xs text-slate-500">{{ $heroSliders->total() }} item(s)</span>
        </div>
        @forelse ($heroSliders as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->title }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.hero-sliders.restore', $record->id) }}" onsubmit="return confirm('Restore this slider?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'hero-sliders', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted hero sliders.</p>
        @endforelse
        <div>{{ $heroSliders->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted SMS Campaigns</h2>
            <span class="text-xs text-slate-500">{{ $smsCampaigns->total() }} item(s)</span>
        </div>
        @forelse ($smsCampaigns as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->title }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.sms-campaigns.restore', $record->id) }}" onsubmit="return confirm('Restore this SMS campaign?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'sms-campaigns', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted sms campaigns.</p>
        @endforelse
        <div>{{ $smsCampaigns->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Visitor Pop-ups</h2>
            <span class="text-xs text-slate-500">{{ $visitorPopups->total() }} item(s)</span>
        </div>
        @forelse ($visitorPopups as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->title }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.visitor-popups.restore', $record->id) }}" onsubmit="return confirm('Restore this visitor pop-up?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
                @include('admin.recycle-bin.partials.permanent-delete-form', ['type' => 'visitor-popups', 'recordId' => $record->id])
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted visitor pop-ups.</p>
        @endforelse
        <div>{{ $visitorPopups->links() }}</div>
    </section>
</div>
@endsection
