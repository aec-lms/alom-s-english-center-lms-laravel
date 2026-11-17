<?php

declare(strict_types=1);

namespace Plugins\Modules\RoleAiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\RoleAiAssistant\Models\AiAssistantLog;
use Plugins\Modules\RoleAiAssistant\Services\AiAssistantService;

class AiAssistantController extends Controller
{
    public function __construct(private readonly AiAssistantService $assistant) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $canViewAll = $actor && ($actor->hasRole('admin') || $actor->hasPermission('ai.assistant.configure'));
        $logs = AiAssistantLog::query()
            ->when(!$canViewAll, fn ($query) => $query->where('user_id', $actor?->id))
            ->latest()
            ->limit(25)
            ->get();

        return view('role-ai-assistant::index', compact('logs'));
    }

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        try {
            $result = $this->assistant->queryAssistant($request->user(), $validated['prompt']);
            return response()->json($result);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['error' => 'AEC LMS AI Assistant is temporarily unavailable. Please check your access or contact the administrator.'], 503);
        }
    }
}
