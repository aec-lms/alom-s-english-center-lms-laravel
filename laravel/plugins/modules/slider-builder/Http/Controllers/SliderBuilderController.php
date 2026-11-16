<?php

declare(strict_types=1);

namespace Plugins\Modules\SliderBuilder\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\SliderBuilder\Models\HeroSlider;

class SliderBuilderController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $sliders = Schema::hasTable('cms_sliders')
            ? HeroSlider::query()->whereIn('key_name', ['hero', 'banner_slider'])->orderBy('sort_order')->get()
            : collect();

        return view('slider-builder::index', compact('sliders'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key_name' => ['required', 'in:hero,banner_slider'],
            'title' => ['required', 'string', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'cta_url' => ['nullable', 'string', 'max:255', 'regex:#^(https?://[^\\s]+|/(?!/)[^\\s]*)$#i'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_path' => ['nullable', 'string', 'max:255', 'regex:#^/(?!/)(?!.*(?:\\.\\.|%2e|%2f))[A-Za-z0-9/_\\-.]+$#i'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $imagePath = $request->hasFile('image')
            ? $this->storeImage($request->file('image'))
            : ($validated['image_path'] ?? null);

        $slider = HeroSlider::query()->create([
            'key_name' => $validated['key_name'],
            'title' => $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'image_path' => $imagePath,
            'cta_url' => $validated['cta_url'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->audit->log('slider_builder.created', 'hero_slider', (string) $slider->id);

        return back()->with('status', "Slider '{$slider->title}' added.");
    }

    public function update(Request $request, HeroSlider $slider): RedirectResponse
    {
        $validated = $request->validate([
            'key_name' => ['required', 'in:hero,banner_slider'],
            'title' => ['required', 'string', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'cta_url' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $imagePath = $request->hasFile('image')
            ? $this->storeImage($request->file('image'))
            : ($validated['image_path'] ?? $slider->image_path);

        $slider->update([
            'key_name' => $validated['key_name'],
            'title' => $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'image_path' => $imagePath,
            'cta_url' => $validated['cta_url'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->audit->log('slider_builder.updated', 'hero_slider', (string) $slider->id);

        return back()->with('status', "Slider '{$slider->title}' updated.");
    }

    public function destroy(HeroSlider $slider): RedirectResponse
    {
        $title = $slider->title;
        $id = (string) $slider->id;
        $slider->delete();
        $this->audit->log('slider_builder.trashed', 'hero_slider', $id);

        return back()->with('status', "Slider '{$title}' moved to the Admin Recycle Bin.");
    }

    private function storeImage(UploadedFile $file): string
    {
        $dir = public_path('uploads/sliders');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = Str::uuid()->toString() . '.' . $file->extension();
        $file->move($dir, $filename);

        return '/uploads/sliders/' . $filename;
    }
}
