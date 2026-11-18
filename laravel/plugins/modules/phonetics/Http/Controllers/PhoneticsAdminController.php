<?php
declare(strict_types=1);
namespace Plugins\\Modules\\Phonetics\\Http\\Controllers;
use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\Request;
use Illuminate\\Http\\UploadedFile;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\File;
use Illuminate\\Support\\Str;
use Illuminate\\View\\View;
use Plugins\\Modules\\Phonetics\\Models\\PhoneticSetting;
use Plugins\\Modules\\Phonetics\\Models\\PhoneticEntry;
final class PhoneticsAdminController extends Controller {
 public function index():View{return view('phonetics::admin.index',['setting'=>PhoneticSetting::current(),'entries'=>PhoneticEntry::query()->orderBy('sort_order')->paginate(30)]);}
 public function updateSettings(Request $r){$v=$r->validate(['enabled'=>'nullable|boolean','course_slug'=>'required|string|max:120','course_title'=>'required|string|max:160','require_course_enrollment'=>'nullable|boolean','show_articulatory_notes'=>'nullable|boolean','page_bg_color'=>'required|regex:/^#[0-9A-Fa-f]{6}$/','default_card_bg_color'=>'required|regex:/^#[0-9A-Fa-f]{6}$/','default_text_color'=>'required|regex:/^#[0-9A-Fa-f]{6}$/','accent_color'=>'required|regex:/^#[0-9A-Fa-f]{6}$/','theme_profile_id'=>'required|in:classic-slate,warm-studio,night-lab,vibrant-classroom,custom']);$palettes=['classic-slate'=>['page_bg_color'=>'#F8FAFC','default_card_bg_color'=>'#FFFFFF','default_text_color'=>'#0F172A','accent_color'=>'#1D4ED8'],'warm-studio'=>['page_bg_color'=>'#FFF7ED','default_card_bg_color'=>'#FFFFFF','default_text_color'=>'#431407','accent_color'=>'#C2410C'],'night-lab'=>['page_bg_color'=>'#0F172A','default_card_bg_color'=>'#1E293B','default_text_color'=>'#F8FAFC','accent_color'=>'#38BDF8'],'vibrant-classroom'=>['page_bg_color'=>'#F0FDF4','default_card_bg_color'=>'#FFFFFF','default_text_color'=>'#052E16','accent_color'=>'#16A34A']];$palette=$v['theme_profile_id']==='custom'?['page_bg_color'=>$v['page_bg_color'],'default_card_bg_color'=>$v['default_card_bg_color'],'default_text_color'=>$v['default_text_color'],'accent_color'=>$v['accent_color']]:$palettes[$v['theme_profile_id']];$s=PhoneticSetting::query()->firstOrNew(['id'=>1]);$s->fill(['enabled'=>$r->boolean('enabled'),'course_slug'=>Str::slug($v['course_slug']),'course_title'=>$v['course_title'],'require_course_enrollment'=>$r->boolean('require_course_enrollment'),'show_articulatory_notes'=>$r->boolean('show_articulatory_notes'),'theme_profile_id'=>$v['theme_profile_id'],'page_bg_color'=>strtoupper($palette['page_bg_color']),'default_card_bg_color'=>strtoupper($palette['default_card_bg_color']),'default_text_color'=>strtoupper($palette['default_text_color']),'accent_color'=>strtoupper($palette['accent_color'])])->save();Cache::forget('phonetics.settings');return back()->with('status','Phonetics settings saved.');}
 public function store(Request $r){
  $v=$r->validate(['word'=>'required|string|max:80','category'=>'required|in:rhoticity,trap-bath,t-flapping,lot-vowel,yod-dropping,word-stress','uk_ipa'=>'required|string|max:100','us_ipa'=>'required|string|max:100','uk_note'=>'nullable|string|max:255','us_note'=>'nullable|string|max:255','example_sentence'=>'nullable|string|max:255','card_bg_color'=>'nullable|regex:/^#[0-9A-Fa-f]{6}$/','text_color'=>'nullable|regex:/^#[0-9A-Fa-f]{6}$/','text_bg_color'=>'nullable|regex:/^#[0-9A-Fa-f]{6}$/','card_animation'=>'nullable|in:none,fade-up,float,pulse-glow,shimmer','uk_audio'=>'nullable|file|mimes:mp3,wav,ogg,m4a,webm|max:5120','us_audio'=>'nullable|file|mimes:mp3,wav,ogg,m4a,webm|max:5120','card_bg_image'=>'nullable|image|mimes:jpg,jpeg,png,webp|max:2048']);
  $paths=[];$v['sort_order']=(PhoneticEntry::query()->max('sort_order')??0)+1;$v['is_active']=true;$v['card_animation']=$v['card_animation']??'none';
  try{
   foreach(['uk_audio'=>'audio/uk','us_audio'=>'audio/us','card_bg_image'=>'images/cards'] as $field=>$dir){if($r->hasFile($field)){$path=$this->storeMedia($r->file($field),$dir);$paths[]=$path;$v[match($field){'uk_audio'=>'uk_audio_path','us_audio'=>'us_audio_path',default=>'card_bg_image_path'}]=$path;}}
   unset($v['uk_audio'],$v['us_audio'],$v['card_bg_image']);PhoneticEntry::query()->create($v);
  }catch(\\Throwable $e){foreach($paths as $path)$this->deleteMedia($path);throw $e;}
  return back()->with('status','Phonetic entry added.');
 }
 public function update(Request $r,PhoneticEntry $entry){
  $v=$r->validate(['word'=>'required|string|max:80','category'=>'required|in:rhoticity,trap-bath,t-flapping,lot-vowel,yod-dropping,word-stress','uk_ipa'=>'required|string|max:100','us_ipa'=>'required|string|max:100','uk_note'=>'nullable|string|max:255','us_note'=>'nullable|string|max:255','example_sentence'=>'nullable|string|max:255','card_bg_color'=>'nullable|regex:/^#[0-9A-Fa-f]{6}$/','text_color'=>'nullable|regex:/^#[0-9A-Fa-f]{6}$/','text_bg_color'=>'nullable|regex:/^#[0-9A-Fa-f]{6}$/','card_animation'=>'nullable|in:none,fade-up,float,pulse-glow,shimmer','is_active'=>'nullable|boolean','uk_audio'=>'nullable|file|mimes:mp3,wav,ogg,m4a,webm|max:5120','us_audio'=>'nullable|file|mimes:mp3,wav,ogg,m4a,webm|max:5120','card_bg_image'=>'nullable|image|mimes:jpg,jpeg,png,webp|max:2048']);
  $newPaths=[];$oldPaths=[];$v['is_active']=$r->boolean('is_active');$v['card_animation']=$v['card_animation']??$entry->card_animation??'none';
  try{
   foreach(['uk_audio'=>'audio/uk','us_audio'=>'audio/us','card_bg_image'=>'images/cards'] as $field=>$dir){if($r->hasFile($field)){$column=match($field){'uk_audio'=>'uk_audio_path','us_audio'=>'us_audio_path',default=>'card_bg_image_path'};$path=$this->storeMedia($r->file($field),$dir);$newPaths[]=$path;if($entry->{$column})$oldPaths[]=$entry->{$column};$v[$column]=$path;}}
   unset($v['uk_audio'],$v['us_audio'],$v['card_bg_image']);$entry->update($v);
  }catch(\\Throwable $e){foreach($newPaths as $path)$this->deleteMedia($path);throw $e;}
  foreach($oldPaths as $path)$this->deleteMedia($path);
  return back()->with('status','Phonetic entry updated.');
 }
 public function destroy(PhoneticEntry $entry){$entry->update(['is_active'=>false]);return back()->with('status','Entry archived. Practice history and media have been preserved.');}
 private function storeMedia(UploadedFile $file,string $subDir):string{
  $mime=(string)$file->getMimeType();
  $extension=match($mime){'audio/mpeg','audio/mp3'=>'mp3','audio/wav','audio/x-wav'=>'wav','audio/ogg','application/ogg'=>'ogg','audio/mp4'=>'m4a','audio/webm','video/webm'=>'webm','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',default=>strtolower((string)$file->guessExtension())};
  $allowed=str_starts_with($subDir,'audio/')?['mp3','wav','ogg','m4a','webm']:['jpg','jpeg','png','webp'];
  if($extension==='mpga')$extension='mp3';abort_unless(in_array($extension,$allowed,true),422,'Unsupported phonetics media format.');
  $relativeDir='uploads/alom-english-center/phonetics/'.trim($subDir,'/');$absoluteDir=public_path($relativeDir);File::ensureDirectoryExists($absoluteDir);$filename=Str::random(32).'.'.$extension;$file->move($absoluteDir,$filename);return $relativeDir.'/'.$filename;
 }
 private function deleteMedia(string $path):void{
  if(!preg_match('~^uploads/alom-english-center/phonetics/(?:audio/(?:uk|us)|images/cards)/[A-Za-z0-9._-]+$~',$path))return;
  $absolute=public_path($path);if(File::exists($absolute))File::delete($absolute);
 }
}