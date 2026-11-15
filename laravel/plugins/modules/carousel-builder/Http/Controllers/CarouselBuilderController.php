<?php

declare(strict_types=1);

namespace Plugins\Modules\CarouselBuilder\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\CarouselBuilder\Models\CarouselItem;

class CarouselBuilderController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $items = Schema::hasTable('cms_carousels')
            ? CarouselItem::query()->orderBy('carousel_group')->orderBy('sort_order')->get()
            : collect();

        return view('carousel-builder::index', compact('items'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'carousel_group' => ['required', 'in:featured_carousel,testimonial_left,testimonial_right,course_showcase,partner_logos'],
            'title' => ['required', 'string', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'cta_url' => ['nullable', 'string', 'max:255'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $imagePath = $request->hasFile('image')
            ? $this->storeImage($request->file('image'))
            : ($validated['image_path'] ?? null);

        $item = CarouselItem::query()->create([
            'carousel_group' => $validated['carousel_group'],
            'title' => $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'image_path' => $imagePath,
            'cta_url' => $validated['cta_url'] ?? null,
            'rating' => (int) ($validated['rating'] ?? 5),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->audit->log('carousel_builder.created', 'carousel_item', (string) $item->id, ['group' => $item->carousel_group]);

        return back()->with('status', "Carousel item '{$item->title}' added.");
    }

    public function update(Request $request, CarouselItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'carousel_group' => ['required', 'in:featured_carousel,testimonial_left,testimonial_right,course_showcase,partner_logos'],
            'title' => ['required', 'string', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'cta_url' => ['nullable', 'string', 'max:255'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $imagePath = $request->hasFile('image')
            ? $this->storeImage($request->file('image'))
            : ($validated['image_path'] ?? $item->image_path);

        $item->update([
            'carousel_group' => $validated['carousel_group'],
            'title' => $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'image_path' => $imagePath,
            'cta_url' => $validated['cta_url'] ?? null,
            'rating' => (int) ($validated['rating'] ?? 5),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->audit->log('carousel_builder.updated', 'carousel_item', (string) $item->id);

        return back()->with('status', "Carousel item '{$item->title}' updated.");
    }

    public function destroy(CarouselItem $item): RedirectResponse
    {
        $title = $item->title;
        $id = (string) $item->id;
        $item->delete();
        $this->audit->log('carousel_builder.deleted', 'carousel_item', $id);

        return back()->with('status', "Carousel item '{$title}' deleted.");
    }

    private function storeImage(UploadedFile $file): string
    {
        $dir = public_path('uploads/carousels');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);

        return '/uploads/carousels/' . $filename;
    }
}
