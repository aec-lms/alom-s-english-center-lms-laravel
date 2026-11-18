<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Core\Plugins\PluginManager;use Tests\TestCase;
final class AlomBrandingLoaderPhoneticsPluginTest extends TestCase{
 public function test_al_branding_loader_and_phonetics_are_independent_plugins():void{$m=app(PluginManager::class)->discoverManifests();$this->assertArrayHasKey('al-branding-loader',$m);$this->assertArrayHasKey('phonetics',$m);$this->assertSame([],$m['al-branding-loader']->dependencies);$this->assertContains('course-management',$m['phonetics']->dependencies);$this->assertNotContains('phonetics',$m['al-branding-loader']->dependencies);$this->assertNotContains('al-branding-loader',$m['phonetics']->dependencies);}
 public function test_admin_routes_are_permission_protected():void{$l=file_get_contents(base_path('plugins/modules/al-branding-loader/Routes/web.php'));$p=file_get_contents(base_path('plugins/modules/phonetics/Routes/web.php'));$this->assertStringContainsString('permission:al-branding-loader.manage',$l);$this->assertStringContainsString('permission:phonetics.manage',$p);}
}