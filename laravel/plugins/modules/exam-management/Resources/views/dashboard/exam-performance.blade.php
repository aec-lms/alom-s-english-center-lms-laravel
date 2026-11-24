@php
    $examSummary = ['attempts' => 0, 'average' => null, 'pass_rate' => null];
    if (\Illuminate\Support\Facades\Schema::hasTable('exam_attempts') && \Illuminate\Support\Facades\Schema::hasTable('exams')) {
        $attempts = \Illuminate\Support\Facades\DB::table('exam_attempts as ea')
            ->join('exams as e', 'e.id', '=', 'ea.exam_id')
            ->whereNotNull('ea.submitted_at')
            ->where('e.is_published', true);
        if (\Illuminate\Support\Facades\Schema::hasColumn('exams', 'deleted_at')) {
            $attempts->whereNull('e.deleted_at');
        }
        $attempts->where('ea.submitted_at', '>=', now()->subDays(30));
        $examSummary['attempts'] = (clone $attempts)->count();
        $examSummary['average'] = (clone $attempts)->selectRaw('AVG(CASE WHEN ea.total_marks > 0 THEN (ea.score_obtained / ea.total_marks) * 100 ELSE NULL END) as average_score')->value('average_score');
        $examSummary['pass_rate'] = (clone $attempts)->selectRaw('AVG(CASE WHEN ea.passed = 1 THEN 100 ELSE 0 END) as pass_rate')->value('pass_rate');

    }
@endphp
<section class="rounded-2xl border border-slate-200 bg-white p-5">
    <div class="mb-4"><h2 class="text-sm font-bold text-slate-900">Exam Performance</h2><p class="mt-1 text-xs text-slate-500">Submitted attempts from the last 30 days</p></div>
    <div class="grid grid-cols-3 gap-3">
        <div class="rounded-xl bg-slate-50 p-3"><p class="text-[11px] text-slate-500">Attempts</p><p class="mt-1 text-xl font-extrabold text-slate-900">{{ number_format($examSummary['attempts']) }}</p></div>
        <div class="rounded-xl bg-slate-50 p-3"><p class="text-[11px] text-slate-500">Avg. score</p><p class="mt-1 text-xl font-extrabold text-slate-900">{{ $examSummary['average'] === null ? '—' : number_format((float) $examSummary['average'], 1) . '%' }}</p></div>
        <div class="rounded-xl bg-slate-50 p-3"><p class="text-[11px] text-slate-500">Pass rate</p><p class="mt-1 text-xl font-extrabold text-slate-900">{{ $examSummary['pass_rate'] === null ? '—' : number_format((float) $examSummary['pass_rate'], 1) . '%' }}</p></div>
    </div>
    @if ($examSummary['attempts'] === 0)
        <p class="mt-4 text-sm text-slate-500">No submitted exam attempts have been recorded in the last 30 days.</p>
    @endif
</section>
