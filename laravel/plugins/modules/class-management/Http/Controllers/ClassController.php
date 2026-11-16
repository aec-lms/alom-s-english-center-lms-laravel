<?php

declare(strict_types=1);

namespace Plugins\Modules\ClassManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\ClassManagement\Models\AcademicClass;

class ClassController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $classes = Schema::hasTable('academic_classes')
            ? AcademicClass::query()
                ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get()
            : collect();

        return view('class-management::index', compact('classes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:academic_classes,code'],
            'name' => ['required', 'string', 'max:160'],
            'section' => ['required', 'string', 'max:60'],
            'subject' => ['required', 'string', 'max:120'],
            'room_number' => ['nullable', 'string', 'max:60'],
            'day_of_week' => ['required', 'string', 'max:24'],
            'start_time' => ['required', 'string', 'max:20'],
            'end_time' => ['required', 'string', 'max:20'],
            'instructor_name' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null) {
            $validated['branch_id'] = $actor->branch_id;
        }

        $item = AcademicClass::query()->create($validated + ['is_active' => true]);
        $this->audit->log('class.created', 'academic_class', (string) $item->id);

        return back()->with('status', "Class '{$item->name} ({$item->section})' scheduled.");
    }

    public function destroy(Request $request, AcademicClass $academicClass): RedirectResponse
    {
        $actor = $request->user();
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $academicClass->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        $name = $academicClass->name;
        $id = (string) $academicClass->id;
        $academicClass->delete();
        $this->audit->log('class.deleted', 'academic_class', $id);

        return back()->with('status', "Class '{$name}' removed.");
    }
}
