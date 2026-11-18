<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseContentStudio\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\CourseContentStudio\Models\CourseContentCategory;
use Plugins\Modules\CourseContentStudio\Models\CourseContentPost;
use Plugins\Modules\CourseContentStudio\Models\CourseCurriculumItem;

class AdminCourseContentStudioController extends Controller
{
    public function index(Request $request): View
    {
        $posts = CourseContentPost::query()->with(['author','categories'])->latest()->paginate(20)->withQueryString();
        $categories = CourseContentCategory::query()->orderBy('sort_order')->orderBy('name')->get();
        $courses = app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses') ? DB::table('courses')->whereNull('deleted_at')->orderBy('title')->get(['id','title']) : collect();
        return view('course-content-studio::admin-index', compact('posts','categories','courses'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data=$request->validate(['name'=>['required','string','max:120'],'description'=>['nullable','string','max:320']]);
        CourseContentCategory::query()->create([
            'name'=>$data['name'],'slug'=>Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
            'description'=>$data['description']??null,'sort_order'=>0,'is_visible'=>true,
        ]);
        return back()->with('status','Content category created.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data=$this->validated($request);
        $data['author_id']=$request->user()->id;
        $categoryIds=$data['category_ids']??[];
        unset($data['category_ids']);
        $post=DB::transaction(function()use($data,$categoryIds,$request){
            if($request->hasFile('thumbnail')) $data['thumbnail_path']=$request->file('thumbnail')->store('course-content/thumbnails','public');
            if($request->hasFile('content_file')) {
                $file=$request->file('content_file');
                $data['content_file_path']=$file->store('course-content/private','local');
                $data['content_file_name']=$file->getClientOriginalName();
                $data['content_file_mime']=$file->getMimeType();
                $data['content_file_size']=$file->getSize();
            }
            $data['slug']=$this->uniqueSlug($data['slug']?:$data['title']);
            $data['tags']=$this->tags($data['tags']??'');
            $data['published_at']=$data['status']==='published'?($data['published_at']??now()):null;
            $post=CourseContentPost::query()->create($data);
            $post->categories()->sync($categoryIds);
            return $post;
        });
        return redirect()->route('plugins.content-studio.admin.index')->with('status','Course content post created.');
    }

    public function update(Request $request,int $post): RedirectResponse
    {
        $record=CourseContentPost::query()->findOrFail($post);
        $data=$this->validated($request);
        $categoryIds=$data['category_ids']??[];
        unset($data['category_ids']);
        $oldThumbnail=$record->thumbnail_path;
        $oldFile=$record->content_file_path;
        $newThumbnail=$request->hasFile('thumbnail')?$request->file('thumbnail')->store('course-content/thumbnails','public'):null;
        $newFile=$request->hasFile('content_file')?$request->file('content_file')->store('course-content/private','local'):null;
        if($newThumbnail)$data['thumbnail_path']=$newThumbnail;
        if($newFile){$file=$request->file('content_file');$data['content_file_path']=$newFile;$data['content_file_name']=$file->getClientOriginalName();$data['content_file_mime']=$file->getMimeType();$data['content_file_size']=$file->getSize();}
        $data['slug']=$this->uniqueSlug($data['slug']?:$data['title'],$record->id);
        $data['tags']=$this->tags($data['tags']??'');
        $data['published_at']=$data['status']==='published'?($data['published_at']??$record->published_at??now()):null;
        $record->update($data);
        $record->categories()->sync($categoryIds);
        if($newThumbnail && $oldThumbnail)Storage::disk('public')->delete($oldThumbnail);
        if($newFile && $oldFile)Storage::disk('local')->delete($oldFile);
        if($request->boolean('remove_thumbnail') && !$newThumbnail){Storage::disk('public')->delete($oldThumbnail);$record->update(['thumbnail_path'=>null]);}
        if($request->boolean('remove_content_file') && !$newFile){Storage::disk('local')->delete($oldFile);$record->update(['content_file_path'=>null,'content_file_name'=>null,'content_file_mime'=>null,'content_file_size'=>null]);}
        return redirect()->route('plugins.content-studio.admin.index')->with('status','Course content post updated.');
    }

    public function archive(int $post): RedirectResponse
    {
        CourseContentPost::query()->findOrFail($post)->update(['status'=>'archived','published_at'=>null]);
        return back()->with('status','Content post archived; curriculum and student history were retained.');
    }

    public function builder(int $course): View
    {
        $this->ensureCourses();
        $record=DB::table('courses')->where('id',$course)->whereNull('deleted_at')->first();
        abort_unless($record,404);
        $posts=CourseContentPost::published()->orderBy('title')->get();
        $items=CourseCurriculumItem::query()->with('post')->where('course_id',$course)->orderBy('position')->get();
        return view('course-content-studio::builder', ['course'=>$record,'posts'=>$posts,'items'=>$items]);
    }

    public function updateBuilder(Request $request,int $course): RedirectResponse
    {
        $this->ensureCourses();
        abort_unless(DB::table('courses')->where('id',$course)->whereNull('deleted_at')->exists(),404);
        $data=$request->validate([
            'items'=>['nullable','array','max:500'],
            'items.*.post_id'=>['required','integer','distinct','exists:course_content_posts,id'],
            'items.*.section_title'=>['nullable','string','max:120'],
            'items.*.position'=>['required','integer','min:0','max:10000'],
            'items.*.duration_minutes'=>['nullable','integer','min:0','max:600'],
            'items.*.is_preview'=>['nullable','boolean'],
            'items.*.is_active'=>['nullable','boolean'],
            'add_post_ids'=>['nullable','array','max:500'],
            'add_post_ids.*'=>['integer','distinct','exists:course_content_posts,id'],
        ]);
        DB::transaction(function()use($course,$data){
            foreach($data['items']??[] as $item){
                CourseCurriculumItem::query()->updateOrCreate(
                    ['course_id'=>$course,'course_content_post_id'=>$item['post_id']],
                    ['section_title'=>$item['section_title']??null,'position'=>$item['position'],'duration_minutes'=>$item['duration_minutes']??null,'is_preview'=>(bool)($item['is_preview']??false),'is_active'=>(bool)($item['is_active']??true)]
                );
            }
            $position=(int)CourseCurriculumItem::query()->where('course_id',$course)->max('position');
            foreach($data['add_post_ids']??[] as $postId){
                $position+=10;
                CourseCurriculumItem::query()->updateOrCreate(
                    ['course_id'=>$course,'course_content_post_id'=>$postId],
                    ['position'=>$position,'is_active'=>true,'is_preview'=>false]
                );
            }
        });
        return back()->with('status','Course curriculum saved. Removed items can be disabled without deleting learning history.');
    }

    private function validated(Request $request): array
    {
        $data=$request->validate([
            'title'=>['required','string','max:180'],
            'slug'=>['nullable','string','max:200','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'short_description'=>['nullable','string','max:500'],
            'long_description'=>['required','string','max:100000'],
            'video_url'=>['nullable','url','max:500'],
            'access_type'=>['required','in:free,course_enrollment'],
            'status'=>['required','in:draft,published'],
            'published_at'=>['nullable','date'],
            'tags'=>['nullable','string','max:1000'],
            'seo_title'=>['nullable','string','max:180'],
            'seo_description'=>['nullable','string','max:320'],
            'category_ids'=>['nullable','array','max:30'],
            'category_ids.*'=>['integer','distinct','exists:course_content_categories,id'],
            'thumbnail'=>['nullable','image','mimes:jpg,jpeg,png,webp,gif','max:5120'],
            'content_file'=>['nullable','file','mimes:pdf,mp4,mp3,jpg,jpeg,png,webp,zip','max:102400'],
            'remove_thumbnail'=>['nullable','boolean'],
            'remove_content_file'=>['nullable','boolean'],
        ]);
        abort_unless(CourseContentPost::safeVideoUrl($data['video_url']??null),422,'Only HTTPS YouTube and Vimeo video URLs are allowed.');
        return $data;
    }

    private function tags(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(fn($tag)=>trim($tag),explode(',',$value)))));
    }

    private function uniqueSlug(string $value,?int $ignore=null): string
    {
        $base=Str::slug($value)?:'lesson';
        $slug=$base;$suffix=2;
        while(CourseContentPost::query()->where('slug',$slug)->when($ignore,fn($q)=>$q->where('id','!=',$ignore))->exists())$slug=$base.'-'.$suffix++;
        return $slug;
    }

    private function ensureCourses(): void
    {
        abort_unless(app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses'),409,'Enable Course Management to build a course curriculum.');
    }
}
