<?php

declare(strict_types=1);

namespace Plugins\Modules\SeoManager\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\SeoManager\Models\SeoMetaRule;

class SeoController extends Controller
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function index(): View
    {
        $global = [
            'site_title_seo' => $this->plugins->getSetting('seo-manager', 'site_title_seo', "Alom's English Center — AEC LMS"),
            'site_meta_description' => $this->plugins->getSetting('seo-manager', 'site_meta_description', 'Structured English learning, IELTS batches, live classes, and online exams.'),
            'canonical_url' => $this->plugins->getSetting('seo-manager', 'canonical_url', url('/')),
            'robots_txt' => $this->plugins->getSetting('seo-manager', 'robots_txt', "User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: " . url('/sitemap.xml')),
        ];
        $rules = Schema::hasTable('seo_meta_rules') ? SeoMetaRule::query()->orderBy('route_path')->get() : collect();

        return view('seo-manager::index', compact('global', 'rules'));
    }

    public function saveGlobal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_title_seo' => ['required', 'string', 'max:200'],
            'site_meta_description' => ['required', 'string', 'max:340'],
            'canonical_url' => ['nullable', 'string', 'max:255'],
            'robots_txt' => ['nullable', 'string', 'max:4000'],
        ]);

        $this->plugins->saveSettings('seo-manager', [
            'site_title_seo' => $validated['site_title_seo'],
            'site_meta_description' => $validated['site_meta_description'],
            'canonical_url' => $validated['canonical_url'] ?? url('/'),
            'robots_txt' => $validated['robots_txt'] ?? "User-agent: *\nAllow: /",
        ], [], auth()->id());

        return back()->with('status', 'Global SEO & Robots.txt settings saved.');
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'route_path' => ['required', 'string', 'max:180'],
            'meta_title' => ['required', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:340'],
            'meta_keywords' => ['nullable', 'string', 'max:340'],
            'canonical_url' => ['nullable', 'string', 'max:255'],
            'robots_directive' => ['required', 'in:index,follow,noindex,follow,index,nofollow,noindex,nofollow'],
        ]);

        $path = '/' . ltrim(trim($validated['route_path']), '/');
        SeoMetaRule::query()->updateOrCreate(
            ['route_path' => $path],
            [
                'meta_title' => $validated['meta_title'],
                'meta_description' => $validated['meta_description'] ?? null,
                'meta_keywords' => $validated['meta_keywords'] ?? null,
                'canonical_url' => $validated['canonical_url'] ?? null,
                'robots_directive' => $validated['robots_directive'],
            ]
        );

        return back()->with('status', "SEO rule saved for route '{$path}'.");
    }

    public function destroyRule(SeoMetaRule $rule): RedirectResponse
    {
        $rule->delete();
        return back()->with('status', 'SEO route rule removed.');
    }

    public function robotsTxt(): Response
    {
        $content = (string) $this->plugins->getSetting(
            'seo-manager',
            'robots_txt',
            "User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: " . url('/sitemap.xml')
        );

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $urls = [
            url('/'),
            url('/courses'),
            url('/learn'),
            url('/instructors'),
            url('/exams'),
            url('/products'),
            url('/ebooks'),
            url('/result'),
            url('/board-result'),
            url('/about-us'),
            url('/contact-us'),
            url('/terms-and-conditions'),
            url('/privacy-policy'),
            url('/refund-policy'),
        ];

        if (Schema::hasTable('courses')) {
            foreach (DB::table('courses')->where('status', 'published')->pluck('slug') as $slug) {
                $urls[] = url('/course/' . $slug);
            }
        }
        if (Schema::hasTable('storefront_items')) {
            foreach (DB::table('storefront_items')->where('is_active', true)->get(['type', 'slug']) as $item) {
                $urls[] = $item->type === 'ebook'
                    ? url('/ebooks/' . $item->slug)
                    : url('/product/' . $item->slug);
            }
        }
        if (Schema::hasTable('cms_pages')) {
            foreach (DB::table('cms_pages')->where('is_published', true)->pluck('slug') as $slug) {
                $urls[] = url('/page/' . $slug);
            }
        }
        if (Schema::hasTable('seo_meta_rules')) {
            foreach (SeoMetaRule::query()->where('robots_directive', 'like', 'index%')->pluck('route_path') as $path) {
                $urls[] = url($path);
            }
        }

        $urls = array_values(array_unique($urls));
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . htmlspecialchars($u, ENT_XML1) . "</loc><changefreq>weekly</changefreq></url>\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
