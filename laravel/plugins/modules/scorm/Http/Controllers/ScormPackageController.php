<?php

declare(strict_types=1);

namespace Plugins\\Modules\\Scorm\\Http\\Controllers;

use App\\Core\\Plugins\\PluginManager;
use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\View\\View;
use Plugins\\Modules\\Scorm\\Services\\ScormPackageImporter;
use RuntimeException;
use Throwable;

class ScormPackageController extends Controller
{
    public function index(Request $request, PluginManager $plugins): View
    {
        abort_unless(Schema::hasTable('scorm_packages'), 503, 'SCORM storage is not installed.');
        $actor = $request->user();
        $packages = DB::table('scorm_packages')
            ->when($actor?->hasRole('branch_manager'), fn ($query) => $query->where('branch_id', $actor->branch_id))
            ->orderByDesc('created_at')
            ->paginate(20);

        $courses = collect();
        if ($plugins->isEnabled('course-management') && Schema::hasTable('courses')) {
            $courses = DB::table('courses')
                ->where('status', 'published')
                ->when($actor?->hasRole('branch_manager'), fn ($query) => $query->where(function ($scope) use ($actor): void {
                    $scope->whereNull('branch_id')->orWhere('branch_id', $actor->branch_id);
                }))
                ->orderBy('title')
                ->get(['id', 'title', 'branch_id']);
        }

        return view('scorm::admin.index', compact('packages', 'courses'));
    }

    public function store(Request $request, PluginManager $plugins, ScormPackageImporter $importer): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->hasRole('admin') || $actor->hasPermission('scorm.manage')), 403);
        abort_unless($plugins->isEnabled('course-management') && Schema::hasTable('courses'), 503, 'Enable Course Management before assigning a SCORM package to a course.');
        abort_unless(Schema::hasTable('scorm_packages'), 503, 'SCORM storage is not installed.');

        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'package' => ['required', 'file', 'mimes:zip', 'max:204800'],
        ]);

        $course = DB::table('courses')->where('id', $validated['course_id'])->where('status', 'published')->first();
        abort_unless($course, 422, 'Choose a published course.');
        if ($actor->hasRole('branch_manager')) {
            abort_unless($actor->branch_id !== null, 403, 'Branch manager must have an assigned branch.');
            abort_unless($course->branch_id === null || (int) $course->branch_id === (int) $actor->branch_id, 403, 'Course is outside your assigned branch.');
        }

        $imported = $importer->import($request->file('package')->getRealPath());
        try {
            DB::table('scorm_packages')->insert([
                'course_id' => $course->id,
                'branch_id' => $course->branch_id ?? $actor->branch_id,
                'created_by' => $actor->id,
                'title' => $imported['title'],
                'identifier' => null,
                'version' => '1.2',
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
        } catch (Throwable $exception) {
            if (is_dir($imported['directory']) && !is_link($imported['directory'])) {
                \\Illuminate\\Support\\Facades\\File::deleteDirectory($imported['directory']);
            }
            throw $exception;
        }

        return redirect()->route('plugins.scorm.admin.index')->with('status', 'SCORM 1.2 package imported and saved as draft. Review it before publishing.');
    }

    public function publish(int $package): RedirectResponse
    {
        $record = DB::table('scorm_packages')->where('id', $package)->first();
        abort_unless($record, 404);
        abort_unless($record->status === 'draft', 409, 'Only draft SCORM packages can be published.');
        abort_unless($record->course_id !== null && Schema::hasTable('courses')
            && DB::table('courses')->where('id', $record->course_id)->where('status', 'published')->exists(),
            422, 'The linked course must remain published.');

        DB::table('scorm_packages')->where('id', $package)->update([
            'status' => 'published',
            'published_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'SCORM package published.');
    }

    public function archive(int $package): RedirectResponse
    {
        $record = DB::table('scorm_packages')->where('id', $package)->first();
        abort_unless($record, 404);
        $activeAttempts = DB::table('scorm_attempts')
            ->where('scorm_package_id', $package)
            ->whereIn('completion_state', ['in_progress', 'suspended'])
            ->count();
        abort_if($activeAttempts > 0, 409, 'This package has active learner attempts and cannot be archived yet.');

        DB::table('scorm_packages')->where('id', $package)->update([
            'status' => 'archived',
            'archived_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'SCORM package archived. Learner attempt history is retained.');
    }
}
