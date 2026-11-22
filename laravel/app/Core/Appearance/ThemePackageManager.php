<?php

declare(strict_types=1);

namespace App\Core\Appearance;

use App\Models\SystemSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

final class ThemePackageManager
{
    private const REGISTRY_KEY='appearance.themes.installed';
    private const ACTIVE_KEY='appearance.themes.active';
    private const BACKUP_KEY='appearance.themes.previous_global';

    public function __construct(private readonly ThemeManager $themes) {}

    public function installed(): array
    {
        if(!\Illuminate\Support\Facades\Schema::hasTable('system_settings')) return [];
        $raw=SystemSetting::query()->where('key',self::REGISTRY_KEY)->value('value');
        $items=is_string($raw)?json_decode($raw,true):[];
        return is_array($items)?$items:[];
    }

    public function activeSlug(): ?string
    {
        if(!\Illuminate\Support\Facades\Schema::hasTable('system_settings')) return null;
        $value=SystemSetting::query()->where('key',self::ACTIVE_KEY)->value('value');
        return is_string($value)&&$value!==''?$value:null;
    }

    public function install(UploadedFile $upload, ?int $userId=null): array
    {
        if(!class_exists(ZipArchive::class)) throw new RuntimeException('PHP ZIP extension is required to install theme packages.');
        $zip=new ZipArchive();
        if($zip->open($upload->getRealPath())!==true) throw new RuntimeException('The uploaded theme package is not a valid ZIP archive.');
        try {
            if($zip->numFiles<1||$zip->numFiles>20) throw new RuntimeException('Theme ZIP must contain no more than 20 files.');
            $total=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);
                if(!$stat) throw new RuntimeException('Theme ZIP contains an unreadable entry.');
                $name=str_replace('\\','/',(string)$stat['name']);
                if(str_starts_with($name,'/')||preg_match('#(^|/)\.\.(/|$)#',$name)) throw new RuntimeException('Theme ZIP contains an unsafe file path.');
                $total+=(int)($stat['size']??0);
                if($total>5*1024*1024) throw new RuntimeException('Uncompressed theme package exceeds 5 MB.');
                if(!in_array($name,['theme.json'],true)) {
                    throw new RuntimeException('Theme packages may contain only a root-level theme.json file. PHP, Blade, JavaScript, CSS and additional files are not allowed.');
                }
            }
            $json=$zip->getFromName('theme.json');
            if(!is_string($json)||strlen($json)>65536) throw new RuntimeException('Theme ZIP must include a theme.json file up to 64 KB.');
            try{$manifest=json_decode($json,true,512,JSON_THROW_ON_ERROR);}
            catch(\JsonException){throw new RuntimeException('theme.json is not valid JSON.');}
            $manifest=$this->validateManifest($manifest);
        } finally {
            $zip->close();
        }

        $registry=$this->installed();
        $old=$registry[$manifest['slug']]??null;
        if(is_array($old)&&version_compare($manifest['version'],(string)($old['version']??'0.0.0'),'<=')) {
            throw new RuntimeException('Theme version must be newer than the installed version.');
        }

        $directory=storage_path('app/aec-themes/'.$manifest['slug']);
        File::ensureDirectoryExists($directory);
        // Keep the last validated package version before an upgrade overwrites it.
        if (is_array($old)) {
            $previousPath=$directory.'/versions/previous.json';
            File::ensureDirectoryExists(dirname($previousPath));
            $currentPath=$directory.'/theme.json';
            if (is_file($currentPath)) File::copy($currentPath,$previousPath);
        }
        File::put($directory.'/theme.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $registry[$manifest['slug']]=[
            'slug'=>$manifest['slug'],'name'=>$manifest['name'],'version'=>$manifest['version'],
            'description'=>$manifest['description'],'author'=>$manifest['author'],
            'installed_at'=>now()->toIso8601String(),
        ];
        $this->writeSetting(self::REGISTRY_KEY,$registry,$userId);
        // If an active package is upgraded in place, apply its new validated tokens immediately.
        if($this->activeSlug()===$manifest['slug']) $this->activate($manifest['slug'],$userId);
        return $registry[$manifest['slug']];
    }

    public function rollback(string $slug, ?int $userId=null): array
    {
        $directory=storage_path('app/aec-themes/'.$slug);
        $currentPath=$directory.'/theme.json';
        $previousPath=$directory.'/versions/previous.json';
        if (!is_file($currentPath) || !is_file($previousPath)) {
            throw new RuntimeException('No previous theme version is available for rollback.');
        }

        $previous=$this->validateManifest(json_decode((string) File::get($previousPath), true));
        $current=$this->validateManifest(json_decode((string) File::get($currentPath), true));
        if ($previous['slug']!==$slug || $current['slug']!==$slug
            || version_compare($previous['version'],$current['version'],'>=')) {
            throw new RuntimeException('Previous theme package is invalid or is not an older version.');
        }

        $temporary=$directory.'/versions/current.json';
        File::put($temporary,json_encode($current,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        File::put($currentPath,json_encode($previous,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        File::move($temporary,$previousPath);

        $registry=$this->installed();
        $registry[$slug]=[
            'slug'=>$slug,'name'=>$previous['name'],'version'=>$previous['version'],
            'description'=>$previous['description'],'author'=>$previous['author'],
            'installed_at'=>now()->toIso8601String(),
        ];
        $this->writeSetting(self::REGISTRY_KEY,$registry,$userId);
        if ($this->activeSlug()===$slug) $this->activate($slug,$userId);

        return $registry[$slug];
    }

    public function hasRollback(string $slug): bool
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)) return false;
        $path=storage_path('app/aec-themes/'.$slug.'/versions/previous.json');
        if (!is_file($path)) return false;
        try {
            $manifest=$this->validateManifest(json_decode((string) File::get($path),true));
            return $manifest['slug']===$slug;
        } catch (\Throwable) {
            return false;
        }
    }

    public function activate(string $slug, ?int $userId=null): void
    {
        $manifest=$this->manifest($slug);
        DB::transaction(function () use ($manifest,$slug,$userId): void {
            // Switching themes first restores the user's original custom appearance.
            if($this->activeSlug()!==null) $this->deactivate($userId);
            $this->writeSetting(self::BACKUP_KEY,[
                'colors'=>SystemSetting::query()->where('key','appearance.theme.global')->value('value'),
                'design'=>SystemSetting::query()->where('key','appearance.design.global')->value('value'),
            ],$userId);
            $this->themes->save('global',array_merge($this->themes->colors('global'),$manifest['colors']),$userId);
            $this->themes->saveDesign('global',array_merge($this->themes->design('global'),$manifest['design']),$userId);
            $this->writeSetting(self::ACTIVE_KEY,$slug,$userId);
        });
    }

    public function deactivate(?int $userId=null): void
    {
        $active=$this->activeSlug();
        if($active===null) return;
        $backupRaw=SystemSetting::query()->where('key',self::BACKUP_KEY)->value('value');
        $backup=is_string($backupRaw)?json_decode($backupRaw,true):[];
        foreach(['colors'=>'appearance.theme.global','design'=>'appearance.design.global'] as $key=>$setting){
            $value=is_array($backup)?($backup[$key]??null):null;
            if(is_string($value)&&$value!==''){
                SystemSetting::query()->updateOrCreate(['key'=>$setting],['value'=>$value,'is_encrypted'=>false,'updated_by'=>$userId]);
            }else{
                SystemSetting::query()->where('key',$setting)->delete();
            }
        }
        SystemSetting::query()->whereIn('key',[self::ACTIVE_KEY,self::BACKUP_KEY])->delete();
    }

    public function uninstall(string $slug, ?int $userId=null): void
    {
        if($this->activeSlug()===$slug) throw new RuntimeException('Deactivate the active theme before uninstalling it.');
        $registry=$this->installed();
        if(!isset($registry[$slug])) throw new RuntimeException('Theme package is not installed.');
        unset($registry[$slug]);
        $this->writeSetting(self::REGISTRY_KEY,$registry,$userId);
        File::deleteDirectory(storage_path('app/aec-themes/'.$slug));
    }

    public function manifest(string $slug): array
    {
        if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)) throw new RuntimeException('Invalid theme slug.');
        $path=storage_path('app/aec-themes/'.$slug.'/theme.json');
        if(!is_file($path)) throw new RuntimeException('Installed theme manifest is missing.');
        $data=json_decode((string)File::get($path),true);
        return $this->validateManifest($data);
    }

    private function validateManifest(mixed $data): array
    {
        if(!is_array($data)) throw new RuntimeException('Theme manifest must be a JSON object.');
        $slug=(string)($data['slug']??'');
        $name=trim((string)($data['name']??''));
        $version=(string)($data['version']??'');
        $description=trim((string)($data['description']??''));
        $author=trim((string)($data['author']??''));
        if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)||strlen($slug)>60) throw new RuntimeException('Theme slug must use lowercase letters, numbers and hyphens.');
        if($name===''||mb_strlen($name)>100) throw new RuntimeException('Theme name is required and limited to 100 characters.');
        if(!preg_match('/^\d+\.\d+\.\d+$/',$version)) throw new RuntimeException('Theme version must use semantic versioning, for example 1.0.0.');
        if(mb_strlen($description)>300||mb_strlen($author)>80) throw new RuntimeException('Theme description or author is too long.');
        $colors=$data['colors']??[];$design=$data['design']??[];
        if(!is_array($colors)||!is_array($design)||($colors===[]&&$design===[])) throw new RuntimeException('Theme must define at least one color or design token.');
        $safeColors=[];
        foreach($colors as $key=>$value){
            if(!array_key_exists($key,ThemeManager::DEFAULTS)||!is_string($value)||!preg_match('/^#[0-9A-Fa-f]{6}$/',$value))
                throw new RuntimeException('Theme contains an unsupported or unsafe color token.');
            $safeColors[$key]=strtoupper($value);
        }
        $allowedDesign=[
            'font_family'=>['plus-jakarta','inter','noto-sans','system'],
            'font_size'=>['14','15','16','17','18'],
            'heading_weight'=>['600','700','800','900'],
            'content_width'=>['960','1120','1280','1440','1600'],
            'border_radius'=>['8','12','16','20','24'],
            'density'=>['compact','comfortable'],
        ];
        $safeDesign=[];
        foreach($design as $key=>$value){
            if(!isset($allowedDesign[$key])||!in_array((string)$value,$allowedDesign[$key],true))
                throw new RuntimeException('Theme contains an unsupported design token.');
            $safeDesign[$key]=(string)$value;
        }
        return ['slug'=>$slug,'name'=>$name,'version'=>$version,'description'=>$description,'author'=>$author,'colors'=>$safeColors,'design'=>$safeDesign];
    }

    private function writeSetting(string $key,mixed $value,?int $userId): void
    {
        SystemSetting::query()->updateOrCreate(['key'=>$key],[
            'value'=>is_string($value)?$value:json_encode($value,JSON_THROW_ON_ERROR),
            'is_encrypted'=>false,'updated_by'=>$userId,
        ]);
    }
}
