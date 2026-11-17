<?php

declare(strict_types=1);

namespace Plugins\Modules\PageBuilder\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\PageBuilder\Models\BuilderPage;

class PageBuilderController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $pages = Schema::hasTable('cms_pages') ? BuilderPage::query()->latest()->get() : collect();
        return view('page-builder::index', compact('pages'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedPage($request);
        $blocks = $this->normalizeBlocks($validated['blocks_json']);
        $bannerPath = $request->hasFile('banner') ? $this->storeImage($request->file('banner')) : null;
        $page = BuilderPage::query()->create([
            'slug'=>strtolower($validated['slug']), 'title'=>$validated['title'],
            'template'=>$validated['template']??'standard', 'banner_image'=>$bannerPath,
            'blocks_json'=>$blocks, 'seo_title'=>$validated['seo_title']??$validated['title'],
            'seo_description'=>$validated['seo_description']??null,
            'is_published'=>$request->boolean('is_published',true),
        ]);
        $this->audit->log('page_builder.created','builder_page',(string)$page->id,['slug'=>$page->slug,'block_count'=>count($blocks)]);
        return back()->with('status',"Page '/page/{$page->slug}' saved.");
    }

    public function update(Request $request, BuilderPage $page): RedirectResponse
    {
        $validated = $this->validatedPage($request,$page);
        $blocks = $this->normalizeBlocks($validated['blocks_json']);
        $bannerPath = $request->hasFile('banner') ? $this->storeImage($request->file('banner')) : $page->banner_image;
        $page->update([
            'slug'=>strtolower($validated['slug']), 'title'=>$validated['title'],
            'template'=>$validated['template']??'standard', 'banner_image'=>$bannerPath,
            'blocks_json'=>$blocks, 'seo_title'=>$validated['seo_title']??$validated['title'],
            'seo_description'=>$validated['seo_description']??null,
            'is_published'=>$request->boolean('is_published'),
        ]);
        $this->audit->log('page_builder.updated','builder_page',(string)$page->id,['slug'=>$page->slug,'block_count'=>count($blocks)]);
        return back()->with('status',"Page '/page/{$page->slug}' updated.");
    }

    public function destroy(BuilderPage $page): RedirectResponse
    {
        $slug=$page->slug; $id=(string)$page->id; $page->delete();
        $this->audit->log('page_builder.trashed','builder_page',$id,['slug'=>$slug]);
        return back()->with('status',"Page '/page/{$slug}' moved to the Admin Recycle Bin.");
    }

    private function validatedPage(Request $request, ?BuilderPage $page=null): array
    {
        $unique='unique:cms_pages,slug'.($page?','.$page->id:'');
        return $request->validate([
            'slug'=>['required','alpha_dash','max:160',$unique],
            'title'=>['required','string','max:180'],
            'template'=>['nullable','in:standard,landing,fullwidth'],
            'seo_title'=>['nullable','string','max:180'],
            'seo_description'=>['nullable','string','max:320'],
            'blocks_json'=>['required','string','max:60000'],
            'banner'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
            'is_published'=>['nullable','boolean'],
        ]);
    }

    private function normalizeBlocks(string $json): array
    {
        try { $raw=json_decode($json,true,512,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { abort(422,'The page layout is invalid JSON.'); }
        if (!is_array($raw)||!array_is_list($raw)||count($raw)<1||count($raw)>30) abort(422,'A page must contain between 1 and 30 content blocks.');
        $blocks=[];
        foreach($raw as $block){
            if(!is_array($block)||!in_array($block['type']??'',['heading','text','paragraph','rich_text','image','button','cta','spacer'],true)) abort(422,'Unsupported content block.');
            $type=$block['type']==='paragraph'||$block['type']==='rich_text'?'text':($block['type']==='cta'?'button':$block['type']);
            $align=in_array($block['align']??'left',['left','center','right'],true)?($block['align']??'left'):'left';
            if($type==='heading'||$type==='text'){
                $content=trim((string)($block['content']??$block['text']??''));
                if($type==='heading'&&($content===''||mb_strlen($content)>180)) abort(422,'Heading text is required and limited to 180 characters.');
                if(mb_strlen($content)>5000) abort(422,'Text blocks are limited to 5,000 characters.');
                $blocks[]=['type'=>$type,'content'=>$content,'align'=>$align];
            }elseif($type==='image'){
                $url=trim((string)($block['url']??''));
                if(!$this->safeUrl($url)||mb_strlen($url)>500) abort(422,'Image URL is invalid.');
                $blocks[]=['type'=>'image','url'=>$url,'alt'=>mb_substr(trim((string)($block['alt']??'')),0,255)];
            }elseif($type==='button'){
                $label=trim((string)($block['label']??$block['content']??'')); $url=trim((string)($block['url']??''));
                if($label===''||mb_strlen($label)>80||!$this->safeUrl($url)) abort(422,'Button requires a label and a safe destination URL.');
                $blocks[]=['type'=>'button','label'=>$label,'url'=>$url,'align'=>$align];
            }else{
                $blocks[]=['type'=>'spacer','height'=>max(16,min(240,(int)($block['height']??48)))];
            }
        }
        return $blocks;
    }

    private function safeUrl(string $url): bool
    {
        if($url===''||str_starts_with($url,'//')) return false;
        if(str_starts_with($url,'/')) return !str_contains($url,'..')&&!str_contains($url,'\\');
        return filter_var($url,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true);
    }

    private function storeImage(UploadedFile $file): string
    {
        $dir=public_path('uploads/pages');
        if(!is_dir($dir)) mkdir($dir,0755,true);
        $filename=Str::uuid()->toString().'.'.$file->extension();
        $file->move($dir,$filename);
        return '/uploads/pages/'.$filename;
    }
}
