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
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Plugins\Modules\PageBuilder\Models\BuilderPage;

class PageBuilderController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $pages=Schema::hasTable('cms_pages')?BuilderPage::query()->latest()->get():collect();
        return view('page-builder::index',compact('pages'));
    }

    public function store(Request $request): RedirectResponse
    {
        $v=$this->validatedPage($request);
        $blocks=$this->normalizeBlocks($v);
        $banner=$request->hasFile('banner')?$this->storeImage($request->file('banner')):null;
        $page=BuilderPage::query()->create([
            'slug'=>strtolower($v['slug']),'title'=>$v['title'],'template'=>$v['template']??'standard',
            'banner_image'=>$banner,'blocks_json'=>$blocks,'seo_title'=>$v['seo_title']??$v['title'],
            'seo_description'=>$v['seo_description']??null,'is_published'=>$request->boolean('is_published',true),
        ]);
        $this->audit->log('page_builder.created','builder_page',(string)$page->id,['slug'=>$page->slug,'block_count'=>count($blocks)]);
        return back()->with('status',"Page '/page/{$page->slug}' saved.");
    }

    public function update(Request $request,BuilderPage $page): RedirectResponse
    {
        $v=$this->validatedPage($request,$page);$blocks=$this->normalizeBlocks($v);
        $banner=$request->hasFile('banner')?$this->storeImage($request->file('banner')):$page->banner_image;
        $page->update([
            'slug'=>strtolower($v['slug']),'title'=>$v['title'],'template'=>$v['template']??'standard',
            'banner_image'=>$banner,'blocks_json'=>$blocks,'seo_title'=>$v['seo_title']??$v['title'],
            'seo_description'=>$v['seo_description']??null,'is_published'=>$request->boolean('is_published'),
        ]);
        $this->audit->log('page_builder.updated','builder_page',(string)$page->id,['slug'=>$page->slug,'block_count'=>count($blocks)]);
        return back()->with('status',"Page '/page/{$page->slug}' updated.");
    }

    public function destroy(BuilderPage $page): RedirectResponse
    {
        $slug=$page->slug;$id=(string)$page->id;$page->delete();
        $this->audit->log('page_builder.trashed','builder_page',$id,['slug'=>$slug]);
        return back()->with('status',"Page '/page/{$slug}' moved to the Admin Recycle Bin.");
    }

    private function validatedPage(Request $request,?BuilderPage $page=null): array
    {
        $unique='unique:cms_pages,slug'.($page?','.$page->id:'');
        return $request->validate([
            'slug'=>['required','alpha_dash','max:160',$unique],
            'title'=>['required','string','max:180'],
            'template'=>['nullable','in:standard,landing,fullwidth'],
            'seo_title'=>['nullable','string','max:180'],
            'seo_description'=>['nullable','string','max:320'],
            'blocks'=>['nullable','array','min:1','max:30'],
            'blocks.*.type'=>['required_with:blocks','in:heading,text,image,button,spacer'],
            'blocks.*.content'=>['nullable','string','max:5000'],
            'blocks.*.url'=>['nullable','string','max:500'],
            'blocks.*.label'=>['nullable','string','max:80'],
            'blocks.*.alt'=>['nullable','string','max:255'],
            'blocks.*.align'=>['nullable','in:left,center,right'],
            'blocks.*.height'=>['nullable','integer','between:16,240'],
            'blocks_json'=>['nullable','string','max:60000'],
            'banner'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
            'is_published'=>['nullable','boolean'],
        ]);
    }

    private function normalizeBlocks(array $v): array
    {
        if(isset($v['blocks_json'])&&$v['blocks_json']!==''){
            try{$raw=json_decode($v['blocks_json'],true,512,JSON_THROW_ON_ERROR);}
            catch(\JsonException){throw ValidationException::withMessages(['blocks_json'=>'The page layout is invalid JSON.']);}
        }else{$raw=$v['blocks']??[];}
        if(!is_array($raw)||!array_is_list($raw)||count($raw)<1||count($raw)>30)
            throw ValidationException::withMessages(['blocks'=>'A page must contain between 1 and 30 content blocks.']);
        $out=[];
        foreach($raw as $i=>$b){
            if(!is_array($b)||!in_array($b['type']??'', ['heading','text','paragraph','rich_text','image','button','cta','spacer'],true))
                throw ValidationException::withMessages(["blocks.{$i}.type"=>'Unsupported content block.']);
            $type=in_array($b['type'],['paragraph','rich_text'],true)?'text':($b['type']==='cta'?'button':$b['type']);
            $align=in_array($b['align']??'left',['left','center','right'],true)?($b['align']??'left'):'left';
            if(in_array($type,['heading','text'],true)){
                $content=trim((string)($b['content']??$b['text']??''));
                if($type==='heading'&&($content===''||mb_strlen($content)>180))
                    throw ValidationException::withMessages(["blocks.{$i}.content"=>'Heading text is required and limited to 180 characters.']);
                if(mb_strlen($content)>5000)
                    throw ValidationException::withMessages(["blocks.{$i}.content"=>'Text blocks are limited to 5,000 characters.']);
                $out[]=['type'=>$type,'content'=>$content,'align'=>$align];
            }elseif($type==='image'){
                $url=trim((string)($b['url']??''));
                if(!$this->safeUrl($url))
                    throw ValidationException::withMessages(["blocks.{$i}.url"=>'Image URL must use a safe relative path or HTTP(S) URL.']);
                $out[]=['type'=>'image','url'=>$url,'alt'=>mb_substr(trim((string)($b['alt']??'')),0,255)];
            }elseif($type==='button'){
                $label=trim((string)($b['content']??$b['label']??''));$url=trim((string)($b['url']??''));
                if($label===''||mb_strlen($label)>80)
                    throw ValidationException::withMessages(["blocks.{$i}.content"=>'Button label is required and limited to 80 characters.']);
                if(!$this->safeUrl($url))
                    throw ValidationException::withMessages(["blocks.{$i}.url"=>'Button URL must use a safe relative path or HTTP(S) URL.']);
                $out[]=['type'=>'button','content'=>$label,'url'=>$url,'align'=>$align];
            }else{$out[]=['type'=>'spacer','height'=>max(16,min(240,(int)($b['height']??48)))];}
        }
        return $out;
    }

    private function safeUrl(string $url): bool
    {
        if($url===''||str_starts_with($url,'//'))return false;
        if(str_starts_with($url,'/'))return !str_contains($url,'..')&&!str_contains($url,'\\');
        return filter_var($url,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true);
    }

    private function storeImage(UploadedFile $file): string
    {
        $dir=public_path('uploads/pages');if(!is_dir($dir))mkdir($dir,0755,true);
        $filename=Str::uuid()->toString().'.'.$file->extension();$file->move($dir,$filename);
        return '/uploads/pages/'.$filename;
    }
}
