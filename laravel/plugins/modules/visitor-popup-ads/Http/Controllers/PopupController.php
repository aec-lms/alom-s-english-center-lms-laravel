<?php

declare(strict_types=1);

namespace Plugins\Modules\VisitorPopupAds\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\VisitorPopupAds\Models\VisitorPopup;

class PopupController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $popups = Schema::hasTable('visitor_popups') ? VisitorPopup::query()->latest()->get() : collect();
        return view('visitor-popup-ads::index', compact('popups'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'badge_text' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:1200'],
            'cta_text' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail): void {
                if ($value === null || $value === '' || (str_starts_with($value, '/') && !str_starts_with($value, '//'))) return;
                $scheme = strtolower((string) parse_url((string) $value, PHP_URL_SCHEME));
                if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) $fail('The CTA destination must be a relative path or an HTTP/HTTPS URL.');
            }],
            'delay_seconds' => ['nullable', 'integer', 'min:0', 'max:120'],
            'frequency' => ['required', 'in:once_per_session,once_per_day,always'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_url' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail): void {
                if ($value === null || $value === '' || (str_starts_with($value, '/') && !str_starts_with($value, '//'))) return;
                $scheme = strtolower((string) parse_url((string) $value, PHP_URL_SCHEME));
                if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) $fail('The image source must be a relative path or an HTTP/HTTPS URL.');
            }],
        ]);

        $imagePath = $request->hasFile('image')
            ? $this->storeUploadedImage($request->file('image'))
            : ($validated['image_url'] ?? null);

        $popup = VisitorPopup::query()->create([
            'title' => $validated['title'],
            'badge_text' => $validated['badge_text'] ?: 'LIMITED OFFER',
            'description' => $validated['description'] ?? null,
            'image_path' => $imagePath,
            'cta_text' => $validated['cta_text'] ?: 'Claim Offer',
            'cta_url' => $validated['cta_url'] ?: '/courses',
            'delay_seconds' => (int) ($validated['delay_seconds'] ?? 3),
            'frequency' => $validated['frequency'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->audit->log('popup.created', 'visitor_popup', (string) $popup->id, ['title' => $popup->title]);

        return back()->with('status', "Visitor pop-up ad '{$popup->title}' created.");
    }

    public function update(Request $request, VisitorPopup $popup): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'badge_text' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:1200'],
            'cta_text' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail): void {
                if ($value === null || $value === '' || (str_starts_with($value, '/') && !str_starts_with($value, '//'))) return;
                $scheme = strtolower((string) parse_url((string) $value, PHP_URL_SCHEME));
                if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) $fail('The CTA destination must be a relative path or an HTTP/HTTPS URL.');
            }],
            'delay_seconds' => ['required', 'integer', 'min:0', 'max:120'],
            'frequency' => ['required', 'in:once_per_session,once_per_day,always'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_url' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail): void {
                if ($value === null || $value === '' || (str_starts_with($value, '/') && !str_starts_with($value, '//'))) return;
                $scheme = strtolower((string) parse_url((string) $value, PHP_URL_SCHEME));
                if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) $fail('The image source must be a relative path or an HTTP/HTTPS URL.');
            }],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        $imagePath = $request->hasFile('image')
            ? $this->storeUploadedImage($request->file('image'))
            : ($request->boolean('remove_image') ? null : (($validated['image_url'] ?? '') !== '' ? $validated['image_url'] : $popup->image_path));

        $popup->update([
            'title' => $validated['title'],
            'badge_text' => $validated['badge_text'] ?: $popup->badge_text,
            'description' => $validated['description'] ?? $popup->description,
            'image_path' => $imagePath,
            'cta_text' => $validated['cta_text'] ?: $popup->cta_text,
            'cta_url' => $validated['cta_url'] ?: $popup->cta_url,
            'delay_seconds' => (int) $validated['delay_seconds'],
            'frequency' => $validated['frequency'],
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->audit->log('popup.updated', 'visitor_popup', (string) $popup->id, ['active' => $popup->is_active]);

        return back()->with('status', "Visitor pop-up ad '{$popup->title}' updated.");
    }

    public function destroy(VisitorPopup $popup): RedirectResponse
    {
        $popup->delete();
        return back()->with('status', 'Visitor pop-up ad deleted.');
    }

    public function trackClick(VisitorPopup $popup): JsonResponse
    {
        $popup->increment('clicks_count');
        return response()->json(['ok' => true]);
    }

    private function storeUploadedImage(UploadedFile $file): string
    {
        $targetDir = public_path('uploads/popups');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $ext = strtolower($file->guessExtension() ?: 'jpg');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new \RuntimeException('Unsupported popup image type.');
        }
        $name = 'popup_' . date('Ymd_His') . '_' . Str::random(6) . '.' . $ext;
        $file->move($targetDir, $name);

        return '/uploads/popups/' . $name;
    }
}
