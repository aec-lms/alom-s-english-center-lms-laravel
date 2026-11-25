<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Appearance\ThemeManager;
use App\Models\SystemSetting;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class ThemePackageManagerTest extends TestCase
{
    public function test_admin_can_upload_activate_and_deactivate_theme_package_restoring_previous_settings(): void
    {
        if (!class_exists(ZipArchive::class)) $this->markTestSkipped('PHP ZIP extension is required.');
        $this->markSystemInstalled();
        $admin=$this->createUserWithRole('admin');
        SystemSetting::query()->create([
            'key'=>'appearance.theme.global',
            'value'=>json_encode(['primary'=>'#123456'],JSON_THROW_ON_ERROR),
            'is_encrypted'=>false,
            'updated_by'=>$admin->id,
        ]);

        $upload=$this->makeThemeZip([
            'slug'=>'ocean-blue','name'=>'Ocean Blue','version'=>'1.0.0',
            'author'=>'AEC','description'=>'A clean blue theme',
            'colors'=>['primary'=>'#075985','accent'=>'#38BDF8'],
            'design'=>['font_family'=>'inter','content_width'=>'1280'],
        ]);

        try {
            $this->actingAs($admin)->post(route('admin.appearance.themes.upload'),['theme_zip'=>$upload])
                ->assertRedirect()
                ->assertSessionHas('status');
            $this->assertDatabaseHas('system_settings',['key'=>'appearance.themes.installed']);
            $this->actingAs($admin)->post(route('admin.appearance.themes.activate','ocean-blue'))
                ->assertRedirect();
            $this->assertSame('ocean-blue',SystemSetting::query()->where('key','appearance.themes.active')->value('value'));
            $this->assertSame('#075985',app(ThemeManager::class)->colors('global')['primary']);
            $this->assertSame('1280',app(ThemeManager::class)->design('global')['content_width']);

            $this->actingAs($admin)->post(route('admin.appearance.themes.deactivate'))->assertRedirect();
            $this->assertDatabaseMissing('system_settings',['key'=>'appearance.themes.active']);
            $this->assertSame('#123456',app(ThemeManager::class)->colors('global')['primary']);
        } finally {
            @unlink($upload->getRealPath());
        }
    }

    public function test_theme_package_rejects_unsafe_color_tokens(): void
    {
        if (!class_exists(ZipArchive::class)) $this->markTestSkipped('PHP ZIP extension is required.');
        $this->markSystemInstalled();
        $admin=$this->createUserWithRole('admin');
        $upload=$this->makeThemeZip([
            'slug'=>'unsafe-theme','name'=>'Unsafe Theme','version'=>'1.0.0',
            'author'=>'Test','description'=>'Invalid colors',
            'colors'=>['primary'=>'red;background:url(javascript:alert(1))'],
            'design'=>[],
        ]);
        try {
            $this->actingAs($admin)->post(route('admin.appearance.themes.upload'),['theme_zip'=>$upload])
                ->assertSessionHasErrors('theme');
            $this->assertDatabaseMissing('system_settings',['key'=>'appearance.themes.installed']);
        } finally {
            @unlink($upload->getRealPath());
        }
    }

    public function test_theme_package_rejects_executable_and_traversal_zip_entries(): void
    {
        if (!class_exists(ZipArchive::class)) $this->markTestSkipped('PHP ZIP extension is required.');
        $this->markSystemInstalled();
        $admin=$this->createUserWithRole('admin');
        $upload=$this->makeThemeZip([
            'slug'=>'safe-name','name'=>'Safe Name','version'=>'1.0.0',
            'author'=>'Test','description'=>'Safe manifest',
            'colors'=>['primary'=>'#123456'],'design'=>[],
        ],['../payload.php'=>'<?php echo "bad";']);
        try {
            $this->actingAs($admin)->post(route('admin.appearance.themes.upload'),['theme_zip'=>$upload])
                ->assertSessionHasErrors('theme');
            $this->assertDatabaseMissing('system_settings',['key'=>'appearance.themes.installed']);
        } finally {
            @unlink($upload->getRealPath());
        }
    }

    public function test_admin_can_preview_installed_theme_without_changing_active_appearance(): void
    {
        if (!class_exists(ZipArchive::class)) $this->markTestSkipped('PHP ZIP extension is required.');
        $this->markSystemInstalled();
        $admin=$this->createUserWithRole('admin');
        SystemSetting::query()->create([
            'key'=>'appearance.theme.global',
            'value'=>json_encode(['primary'=>'#123456'],JSON_THROW_ON_ERROR),
            'is_encrypted'=>false,
            'updated_by'=>$admin->id,
        ]);
        $upload=$this->makeThemeZip([
            'slug'=>'preview-ocean','name'=>'Preview Ocean','version'=>'1.0.0',
            'author'=>'AEC','description'=>'Preview without activation',
            'colors'=>['primary'=>'#075985','accent'=>'#38BDF8'],
            'design'=>['font_family'=>'inter','content_width'=>'1280'],
        ]);

        try {
            $this->actingAs($admin)->post(route('admin.appearance.themes.upload'),['theme_zip'=>$upload])
                ->assertRedirect()->assertSessionHas('status');

            $this->actingAs($admin)->get(route('admin.appearance.themes.preview','preview-ocean'))
                ->assertOk()
                ->assertSee('Preview Ocean')
                ->assertSee('Read-only preview')
                ->assertSee('#075985')
                ->assertSee('Activate this theme');

            $this->assertDatabaseMissing('system_settings',['key'=>'appearance.themes.active']);
            $this->assertSame('#123456',app(\App\Core\Appearance\ThemeManager::class)->colors('global')['primary']);
        } finally {
            @unlink($upload->getRealPath());
        }
    }


    public function test_theme_upgrade_keeps_previous_version_and_admin_can_rollback(): void
    {
        if (!class_exists(ZipArchive::class)) $this->markTestSkipped('PHP ZIP extension is required.');
        $this->markSystemInstalled();
        $admin=$this->createUserWithRole('admin');
        $first=$this->makeThemeZip([
            'slug'=>'versioned-ocean','name'=>'Versioned Ocean','version'=>'1.0.0',
            'author'=>'AEC','description'=>'First version','colors'=>['primary'=>'#123456'],'design'=>[],
        ]);
        $second=$this->makeThemeZip([
            'slug'=>'versioned-ocean','name'=>'Versioned Ocean','version'=>'1.1.0',
            'author'=>'AEC','description'=>'Second version','colors'=>['primary'=>'#075985'],'design'=>[],
        ]);

        try {
            $this->actingAs($admin)->post(route('admin.appearance.themes.upload'),['theme_zip'=>$first])->assertRedirect()->assertSessionHas('status');
            $this->actingAs($admin)->post(route('admin.appearance.themes.upload'),['theme_zip'=>$second])->assertRedirect()->assertSessionHas('status');
            $this->assertSame('1.1.0',app(\App\Core\Appearance\ThemePackageManager::class)->manifest('versioned-ocean')['version']);
            $this->assertTrue(app(\App\Core\Appearance\ThemePackageManager::class)->hasRollback('versioned-ocean'));

            $this->actingAs($admin)->post(route('admin.appearance.themes.rollback','versioned-ocean'))
                ->assertRedirect()->assertSessionHas('status');
            $this->assertSame('1.0.0',app(\App\Core\Appearance\ThemePackageManager::class)->manifest('versioned-ocean')['version']);
            $this->assertDatabaseHas('system_settings',['key'=>'appearance.themes.installed']);
        } finally {
            @unlink($first->getRealPath());
            @unlink($second->getRealPath());
        }
    }

    private function makeThemeZip(array $manifest,array $extra=[]): UploadedFile
    {
        $path=tempnam(sys_get_temp_dir(),'aec-theme-');
        $zip=new ZipArchive();
        $zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);
        $zip->addFromString('theme.json',json_encode($manifest,JSON_THROW_ON_ERROR));
        foreach($extra as $name=>$contents)$zip->addFromString($name,$contents);
        $zip->close();
        return new UploadedFile($path,'theme-package.zip','application/zip',null,true);
    }
}
