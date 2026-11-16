<?php

declare(strict_types=1);

namespace Plugins\Modules\CertificateManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\CertificateManagement\Models\CertificateRecord;

class CertificateController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $certificates = Schema::hasTable('certificates')
            ? CertificateRecord::query()
                ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderByDesc('id')
                ->get()
            : collect();

        return view('certificate-management::index', compact('certificates'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'student_name' => ['required', 'string', 'max:160'],
            'student_code' => ['nullable', 'string', 'max:60'],
            'course_title' => ['required', 'string', 'max:200'],
            'grade_or_band' => ['required', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:240'],
            'issued_at' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null) {
            $validated['branch_id'] = $actor->branch_id;
        }

        $cert = CertificateRecord::query()->create($validated + [
            'verification_code' => 'AEC-CERT-' . strtoupper(Str::random(8)),
            'status' => 'issued',
            'issued_by' => $actor?->id,
        ]);

        $this->audit->log('certificate.issued', 'certificate', (string) $cert->id, [
            'verification_code' => $cert->verification_code,
        ]);

        return back()->with('status', "Certificate issued! Verification Code: {$cert->verification_code}");
    }

    public function revoke(Request $request, CertificateRecord $certificate): RedirectResponse
    {
        $actor = $request->user();
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $certificate->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        $certificate->update(['status' => 'revoked']);
        $this->audit->log('certificate.revoked', 'certificate', (string) $certificate->id);

        return back()->with('status', "Certificate {$certificate->verification_code} revoked.");
    }
}
