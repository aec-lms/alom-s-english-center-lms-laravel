<?php

declare(strict_types=1);

namespace Plugins\Modules\Scorm\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\Scorm\Services\ScormPackageImporter;
use RuntimeException;
use Throwable;

class ScormPackageController extends Controller
{
    public function index(Request $request, PluginManager $plugins): View
    {
        abort_unless(Schema::hasTable('scorm_packages'), 503, 'SCORM storage is not installed.');
        $actor = $request->user();
        $attemptStats = DB::table('scorm_attempts')
            ->select('scorm_package_id')
            ->selectRaw('COUNT(*) as attempts_count')
            ->selectRaw("SUM(CASE WHEN completion_state = 'completed' THEN 1 ELSE 0 END) as completed_count")
            ->selectRaw("SUM(CASE WHEN completion_state IN ('in_progress', 'suspended') THEN 1 ELSE 0 END) as active_count")
            ->selectRaw('AVG(score_raw) as average_score')
            ->groupBy('scorm_package_id');

        $packages = DB::table('scorm_packages as packages')
            ->leftJoinSub($attemptStats, 'attempt_stats', fn ($join) => $join->on('attempt_stats.scorm_package_id', '=', 'packages.id'))
            ->when($actor?->hasRole('branch_manager'), fn ($query) => $query->where('packages.branch_id', $actor->branch_id))
            ->select('packages.*')
            ->selectRaw('COALESCE(attempt_stats.attempts_count, 0) as attempts_count')
            ->selectRaw('COALESCE(attempt_stats.completed_count, 0) as completed_count')
            ->selectRaw('COALESCE(attempt_stats.active_count, 0) as active_count')
            ->selectRaw('attempt_stats.average_score as average_score')
            ->orderByDesc('packages.created_at')
            ->paginate(20);

        $replacementOptions = collect();
        $courses = collect();
        if ($plugins->isEnabled('course-management') && Schema::hasTable('courses')) {
            $replacementOptions = DB::table('scorm_packages as packages')
                ->join('courses', 'courses.id', '=', 'packages.course_id')
                ->where('packages.status', 'published')
                ->where('courses.status', 'published')
                ->when($actor?->hasRole('branch_manager'), fn ($query) => $query->where('packages.branch_id', $actor->branch_id))
                ->orderBy('courses.title')
                ->orderBy('packages.title')
                ->get(['packages.id', 'packages.course_id', 'packages.title', 'packages.revision_number', 'courses.title as course_title']);
            $courses = DB::table('courses')
                ->where('status', 'published')
                ->when($actor?->hasRole('branch_manager'), fn ($query) => $query->where(function ($scope) use ($actor): void {
                    $scope->whereNull('branch_id')->orWhere('branch_id', $actor->branch_id);
                }))
                ->orderBy('title')
                ->get(['id', 'title', 'branch_id']);
        }

        return view('scorm::admin.index', compact('packages', 'courses', 'replacementOptions'));
    }

    public function store(Request $request, PluginManager $plugins, ScormPackageImporter $importer): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->hasRole('admin') || $actor->hasPermission('scorm.manage')), 403);
        abort_unless($plugins->isEnabled('course-management') && Schema::hasTable('courses'), 503, 'Enable Course Management before assigning a SCORM package to a course.');
        abort_unless(Schema::hasTable('scorm_packages'), 503, 'SCORM storage is not installed.');

        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'replace_package_id' => ['nullable', 'integer', 'exists:scorm_packages,id'],
            'package' => ['required', 'file', 'mimes:zip', 'max:204800'],
        ]);

        $course = DB::table('courses')->where('id', $validated['course_id'])->where('status', 'published')->first();
        abort_unless($course, 422, 'Choose a published course.');
        if ($actor->hasRole('branch_manager')) {
            abort_unless($actor->branch_id !== null, 403, 'Branch manager must have an assigned branch.');
            abort_unless($course->branch_id === null || (int) $course->branch_id === (int) $actor->branch_id, 403, 'Course is outside your assigned branch.');
        }

        $archivePath = $request->file('package')?->getRealPath();
        abort_unless(is_string($archivePath) && $archivePath !== '', 422, 'Uploaded SCORM package is not readable.');
        $imported = $importer->import($archivePath);

        try {
            $packageId = DB::transaction(function () use ($validated, $course, $actor, $imported, $request): int {
                $replacement = null;
                $revisionNumber = 1;
                if (!empty($validated['replace_package_id'])) {
                    $replacement = DB::table('scorm_packages')
                        ->where('id', $validated['replace_package_id'])
                        ->lockForUpdate()
                        ->first();
                    abort_unless($replacement, 404, 'The package selected for replacement no longer exists.');
                    $this->assertBranchAccess($request, $replacement);
                    abort_unless((int) $replacement->course_id === (int) $course->id, 422, 'A replacement package must belong to the same course.');
                    abort_unless($replacement->status === 'published', 409, 'Only a published package can be replaced.');
                    $revisionNumber = (int) ($replacement->revision_number ?? 1) + 1;
                }

                $packageId = DB::table('scorm_packages')->insertGetId([
                    'course_id' => $course->id,
                    'branch_id' => $course->branch_id ?? $actor->branch_id,
                    'created_by' => $actor->id,
                    'title' => $imported['title'],
                    'identifier' => null,
                    'version' => '1.2',
                    'revision_number' => $revisionNumber,
                    'replaces_package_id' => $replacement?->id,
                    'storage_directory' => basename($imported['directory']),
                    'launch_file' => $imported['launch_file'],
                    'manifest_file' => $imported['manifest'],
                    'package_sha256' => $imported['package_sha256'],
                    'file_count' => $imported['file_count'],
                    'expanded_bytes' => $imported['total_bytes'],
                    'status' => 'draft',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $packageId;
            });
        } catch (Throwable $exception) {
            if (is_dir($imported['directory']) && !is_link($imported['directory'])) {
                File::deleteDirectory($imported['directory']);
            }
            throw $exception;
        }

        $revisionNumber = (int) DB::table('scorm_packages')->where('id', $packageId)->value('revision_number');
        $message = !empty($validated['replace_package_id'])
            ? 'Replacement imported as revision ' . $revisionNumber . ' in draft state. The current published revision remains available until the replacement is approved and published.'
            : 'SCORM 1.2 package imported and saved as draft. Review it before publishing.';

        return redirect()->route('plugins.scorm.admin.index')->with('status', $message);
    }

    public function publish(Request $request, int $package): RedirectResponse
    {
        DB::transaction(function () use ($request, $package): void {
            $record = DB::table('scorm_packages')->where('id', $package)->lockForUpdate()->first();
            abort_unless($record, 404);
            $this->assertBranchAccess($request, $record);
            abort_unless($record->status === 'draft', 409, 'Only draft SCORM packages can be published.');
            abort_unless($record->course_id !== null && Schema::hasTable('courses')
                && DB::table('courses')->where('id', $record->course_id)->where('status', 'published')->exists(),
                422, 'The linked course must remain published.');

            if ($record->replaces_package_id !== null) {
                $previous = DB::table('scorm_packages')->where('id', $record->replaces_package_id)->lockForUpdate()->first();
                abort_unless($previous && $previous->status === 'published'
                    && (int) $previous->course_id === (int) $record->course_id
                    && (int) $previous->branch_id === (int) $record->branch_id, 409,
                    'The previous package is no longer the published revision for this course and branch.');
                $activeAttempts = DB::table('scorm_attempts')
                    ->where('scorm_package_id', $previous->id)
                    ->whereIn('completion_state', ['in_progress', 'suspended'])
                    ->lockForUpdate()
                    ->get(['id']);
                abort_if($activeAttempts->isNotEmpty(), 409, 'The previous revision still has active learner attempts. Finish or suspend them before publishing the replacement.');
                DB::table('scorm_packages')->where('id', $previous->id)->update([
                    'status' => 'superseded',
                    'superseded_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('scorm_packages')->where('id', $package)->update([
                'status' => 'published',
                'published_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'SCORM package published.');
    }

    public function archive(Request $request, int $package): RedirectResponse
    {
        DB::transaction(function () use ($request, $package): void {
            $record = DB::table('scorm_packages')->where('id', $package)->lockForUpdate()->first();
            abort_unless($record, 404);
            $this->assertBranchAccess($request, $record);
            abort_unless($record->status === 'published', 409, 'Only a published package can be archived.');

            $activeAttempts = DB::table('scorm_attempts')
                ->where('scorm_package_id', $package)
                ->whereIn('completion_state', ['in_progress', 'suspended'])
                ->lockForUpdate()
                ->get(['id']);
            abort_if($activeAttempts->isNotEmpty(), 409, 'This package has active learner attempts and cannot be archived yet.');

            DB::table('scorm_packages')->where('id', $package)->update([
                'status' => 'archived',
                'archived_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'SCORM package archived. Learner attempt history is retained.');
    }

    private function assertBranchAccess(Request $request, object $package): void
    {
        $actor = $request->user();
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless($actor->branch_id !== null && (int) $package->branch_id === (int) $actor->branch_id, 403, 'SCORM package is outside your assigned branch.');
        }
    }
}
