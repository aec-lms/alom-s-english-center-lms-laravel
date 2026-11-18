<?php

declare(strict_types=1);

namespace Plugins\Modules\Phonetics\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PhoneticsPracticeController extends Controller
{
    /**
     * Backward-compatible route: the reusable course-scoped learning page
     * now owns the practice experience for AEC and standalone Laravel LMSs.
     */
    public function index(Request $request, string $courseSlug): RedirectResponse
    {
        return redirect()->route('plugins.phonetics.learning.index', ['courseSlug' => $courseSlug]);
    }

    public function storeAttempt(Request $request, string $courseSlug): RedirectResponse
    {
        return redirect()->route('plugins.phonetics.learning.index', ['courseSlug' => $courseSlug])
            ->with('status', 'Please submit practice answers from the course vocabulary page.');
    }
}
