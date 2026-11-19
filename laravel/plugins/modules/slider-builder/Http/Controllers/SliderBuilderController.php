<?php

declare(strict_types=1);

namespace Plugins\Modules\SliderBuilder\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Plugins\Modules\SliderBuilder\Models\HeroSlider;

class SliderBuilderController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $sliders = Schema::hasTable('cms_sliders')
            ? HeroSlider::query()->orderBy('key_name')->orderBy('sort_order')->orderBy('id')->get()
            : collect();

        return view('slider-builder::index', compact('sliders'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['image_path'] = $request->hasFile('image')
            ? $this->storeImage($request->file('image'))
            : ($validated['image_path'] ?? null);
        $validated['mobile_image_path'] = $request->hasFile('mobile_image')
            ? $this->storeImage($request->file('mobile_image'))
            : ($validated['mobile_image_path'] ?? null);
        unset($validated['image'], $validated['mobile_image']);
        $validated['text_alignment'] = $validated['text_alignment'] ?? 'center';
        $validated['transition'] = $validated['transition'] ?? 'fade';
        $validated['overlay_opacity'] = (int) ($validated['overlay_opacity'] ?? 45);
        $validated['duration_ms'] = (int) ($validated['duration_ms'] ?? 5000);
        $validated['is_active'] = $request->boolean('is_active', true);

        $slider = HeroSlider::query()->create($validated);
        $this->audit->log('slider_builder.created', 'hero_slider', (string) $slider->id, [
            'group' => $slider->key_name,
            'scheduled' => $slider->starts_at !== null || $slider->ends_at !== null,
        ]);

        return back()->with('status', "Slider '{$slider->title}' added.");
    }

    public function update(Request $request, HeroSlider $slider): RedirectResponse
    {
        $validated = $this->validated($request, $slider);

        // Compare both effective boundaries so a partial update cannot create
        // an invalid window by retaining the omitted value from the database.
        $effectiveStart = array_key_exists('starts_at', $validated) ? $validated['starts_at'] : $slider->starts_at;
        $effectiveEnd = array_key_exists('ends_at', $validated) ? $validated['ends_at'] : $slider->ends_at;
        if ($effectiveStart && $effectiveEnd
            && Carbon::parse($effectiveEnd)->lt(Carbon::parse($effectiveStart))) {
            throw ValidationException::withMessages([
                'ends_at' => 'The slide end date must be on or after its start date.',
            ]);
        }

        $oldImage = $slider->image_path;
        $oldMobileImage = $slider->mobile_image_path;

        if ($request->hasFile('image')) {
            $validated['image_path'] = $this->storeImage($request->file('image'));
        } else {
            $validated['image_path'] = $validated['image_path'] ?? $slider->image_path;
        }
        if ($request->hasFile('mobile_image')) {
            $validated['mobile_image_path'] = $this->storeImage($request->file('mobile_image'));
        } else {
            $validated['mobile_image_path'] = $validated['mobile_image_path'] ?? $slider->mobile_image_path;
        }

        unset($validated['image'], $validated['mobile_image']);
        $validated['text_alignment'] = $validated['text_alignment'] ?? $slider->text_alignment ?? 'center';
        $validated['transition'] = $validated['transition'] ?? $slider->transition ?? 'fade';
        $validated['overlay_opacity'] = (int) ($validated['overlay_opacity'] ?? $slider->overlay_opacity ?? 45);
        $validated['duration_ms'] = (int) ($validated['duration_ms'] ?? $slider->duration_ms ?? 5000);
        $validated['is_active'] = $request->boolean('is_active');
        $slider->update($validated);

        $this->deleteManagedImageIfUnused($oldImage);
        $this->deleteManagedImageIfUnused($oldMobileImage);
        $this->audit->log('slider_builder.updated', 'hero_slider', (string) $slider->id);

        return back()->with('status', "Slider '{$slider->title}' updated.");
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key_name' => ['required', Rule::in(['hero', 'banner_slider', 'course_promotion', 'announcement'])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer', 'distinct'],
        ]);

        $ids = collect($validated['items'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $slides = HeroSlider::query()->where('key_name', $validated['key_name'])->whereIn('id', $ids)->get();
        if ($slides->count() !== count($ids)) {
            return response()->json(['message' => 'One or more slides are unavailable for this placement.'], 422);
        }

        DB::transaction(function () use ($ids, $validated): void {
            foreach ($ids as $position => $id) {
                HeroSlider::query()
                    ->whereKey($id)
                    ->where('key_name', $validated['key_name'])
                    ->update(['sort_order' => ($position + 1) * 10, 'updated_at' => now()]);
            }
        });

        $this->audit->log('slider_builder.reordered', 'hero_slider_group', $validated['key_name'], ['slide_ids' => $ids]);

        return response()->json(['success' => true, 'message' => 'Slide order saved.']);
    }

    public function destroy(HeroSlider $slider): RedirectResponse
    {
        $id = (string) $slider->id;
        $slider->delete();
        $this->audit->log('slider_builder.trashed', 'hero_slider', $id);

        return back()->with('status', "Slider '{$slider->title}' moved to the Admin Recycle Bin.");
    }

    private function validated(Request $request, ?HeroSlider $slider = null): array
    {
        return $request->validate([
            'key_name' => ['required', Rule::in(['hero', 'banner_slider', 'course_promotion', 'announcement'])],
            'title' => ['required', 'string', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'button_label' => ['nullable', 'string', 'max:60'],
            'cta_url' => ['nullable', 'string', 'max:255', 'regex:~^(https?://[^\s]+|/(?!/)[^\s]*|#[A-Za-z][A-Za-z0-9_-]*)$~i'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'mobile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'image_path' => ['nullable', 'string', 'max:255', 'regex:#^/(?!/)(?!.*(?:\.\.|%2e|%2f))[A-Za-z0-9/_\-.]+$#i'],
            'mobile_image_path' => ['nullable', 'string', 'max:255', 'regex:#^/(?!/)(?!.*(?:\.\.|%2e|%2f))[A-Za-z0-9/_\-.]+$#i'],
            'text_alignment' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'transition' => ['nullable', Rule::in(['fade', 'slide'])],
            'overlay_opacity' => ['nullable', 'integer', 'min:0', 'max:90'],
            'duration_ms' => ['nullable', 'integer', 'min:2000', 'max:15000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'open_new_tab' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function storeImage(UploadedFile $file): string
    {
        $dir = public_path('uploads/sliders');
        File::ensureDirectoryExists($dir);
        $extension = strtolower((string) $file->extension());
        $filename = Str::uuid()->toString() . '.' . $extension;
        $file->move($dir, $filename);

        return '/uploads/sliders/' . $filename;
    }

    private function deleteManagedImageIfUnused(?string $path): void
    {
        if (!is_string($path) || !preg_match('~^/uploads/sliders/[A-Za-z0-9-]+\.(?:jpg|jpeg|png|webp)$~i', $path)) {
            return;
        }
        if (HeroSlider::withTrashed()->where('image_path', $path)->orWhere('mobile_image_path', $path)->exists()) {
            return;
        }
        $file = public_path(ltrim($path, '/'));
        if (is_file($file) && !is_link($file)) {
            @unlink($file);
        }
    }
}
