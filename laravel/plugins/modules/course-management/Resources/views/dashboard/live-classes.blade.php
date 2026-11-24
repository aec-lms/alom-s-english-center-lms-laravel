@php
    $upcomingClasses = collect();
    $hasLiveClassTables = \Illuminate\Support\Facades\Schema::hasTable('live_classes')
        && \Illuminate\Support\Facades\Schema::hasTable('courses')
        && \Illuminate\Support\Facades\Schema::hasColumn('live_classes', 'starts_at');
    if ($hasLiveClassTables) {
        $upcomingClasses = \Illuminate\Support\Facades\DB::table('live_classes as lc')
            ->join('courses as c', 'c.id', '=', 'lc.course_id')
                ->whereIn('lc.status', ['upcoming', 'live'])
            ->where(function ($query): void {
                $query->where('lc.starts_at', '>=', now())->orWhere('lc.status', 'live');
            })
            ->orderByRaw("CASE WHEN lc.status = 'live' THEN 0 ELSE 1 END")
            ->orderBy('lc.starts_at')
            ->limit(6)
            ->get(['lc.id', 'lc.title', 'lc.starts_at', 'lc.status', 'c.title as course_title']);
        if (\Illuminate\Support\Facades\Schema::hasTable('live_class_participants')) {
            $participantCounts = \Illuminate\Support\Facades\DB::table('live_class_participants')
                ->whereNull('left_at')
                ->whereIn('live_class_id', $upcomingClasses->pluck('id'))
                ->select('live_class_id')
                ->selectRaw('COUNT(*) as active_count')
                ->groupBy('live_class_id')
                ->pluck('active_count', 'live_class_id');
            $upcomingClasses->each(fn ($class) => $class->active_participants = (int) ($participantCounts[$class->id] ?? 0));
        } else {
            $upcomingClasses->each(fn ($class) => $class->active_participants = 0);
        }
    }
@endphp
<section class="rounded-2xl border border-slate-200 bg-white p-5">
    <div class="mb-4 flex items-center justify-between gap-3">
        <div><h2 class="text-sm font-bold text-slate-900">Live & Upcoming Classes</h2><p class="mt-1 text-xs text-slate-500">Scheduled sessions from active courses</p></div>
        <a href="{{ route('plugins.courses.index') }}" class="text-xs font-semibold text-indigo-700 hover:underline">Manage classes</a>
    </div>
    @forelse ($upcomingClasses as $class)
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 py-3">
            <div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-800">{{ $class->title }}</p><p class="mt-1 text-xs text-slate-500">{{ $class->course_title }} · {{ $class->starts_at ? \Illuminate\Support\Carbon::parse($class->starts_at)->format('M j, Y · g:i A') : 'Time not set' }}</p></div>
            <div class="flex items-center gap-3 text-xs"><span class="rounded-full px-2 py-1 {{ $class->status === 'live' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($class->status) }}</span><span class="text-slate-500">{{ $class->active_participants }} active</span></div>
        </div>
    @empty
        <p class="border-t border-slate-100 py-5 text-sm text-slate-500">No live or upcoming classes are scheduled.</p>
    @endforelse
</section>
