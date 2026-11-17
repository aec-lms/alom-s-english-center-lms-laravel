<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Appearance\SiteLayoutManager;
use Tests\TestCase;

class SiteLayoutBuilderTest extends TestCase
{
    public function test_admin_can_reorder_and_toggle_header_and_footer_sections(): void
    {
        $this->markSystemInstalled();
        $admin=$this->createUserWithRole('admin',null,'site-layout-admin@aec.test');

        $this->actingAs($admin)->get(route('admin.site-layout.index'))
            ->assertOk()
            ->assertSee('Header &amp; Footer Builder')
            ->assertSee('Website Header')
            ->assertSee('Website Footer');

        $this->actingAs($admin)->post(route('admin.site-layout.update'),[
            'header'=>[
                'style'=>'centered',
                'sticky'=>'0',
                'sections'=>[
                    ['key'=>'navigation','visible'=>'1','order'=>10],
                    ['key'=>'brand','visible'=>'1','order'=>20],
                    ['key'=>'actions','visible'=>'1','order'=>30],
                ],
            ],
            'footer'=>[
                'columns'=>'3',
                'show_payment_strip'=>'0',
                'sections'=>[
                    ['key'=>'links','visible'=>'1','order'=>10],
                    ['key'=>'brand','visible'=>'1','order'=>20],
                    ['key'=>'company','visible'=>'0','order'=>30],
                    ['key'=>'contact','visible'=>'1','order'=>40],
                ],
            ],
        ])->assertRedirect(route('admin.site-layout.index'));

        $header=app(SiteLayoutManager::class)->header();
        $footer=app(SiteLayoutManager::class)->footer();
        $this->assertSame('centered',$header['style']);
        $this->assertFalse($header['sticky']);
        $this->assertSame('navigation',array_key_first($header['sections']));
        $this->assertSame(3,$footer['columns']);
        $this->assertFalse($footer['show_payment_strip']);
        $this->assertFalse($footer['sections']['company']['visible']);
        $this->assertDatabaseHas('audit_logs',['action'=>'site.layout.updated']);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-header-style="centered"',false)
            ->assertSee('display:none',false)
            ->assertDontSee('Pay With');
    }

    public function test_layout_builder_requires_at_least_one_visible_section_in_each_area(): void
    {
        $this->markSystemInstalled();
        $admin=$this->createUserWithRole('admin',null,'site-layout-invalid@aec.test');

        $response=$this->actingAs($admin)->post(route('admin.site-layout.update'),[
            'header'=>[
                'style'=>'standard','sticky'=>'1',
                'sections'=>[
                    ['key'=>'brand','visible'=>'0','order'=>10],
                    ['key'=>'navigation','visible'=>'0','order'=>20],
                    ['key'=>'actions','visible'=>'0','order'=>30],
                ],
            ],
            'footer'=>[
                'columns'=>'4','show_payment_strip'=>'1',
                'sections'=>[
                    ['key'=>'brand','visible'=>'1','order'=>10],
                    ['key'=>'links','visible'=>'1','order'=>20],
                    ['key'=>'company','visible'=>'1','order'=>30],
                    ['key'=>'contact','visible'=>'1','order'=>40],
                ],
            ],
        ]);
        $response->assertStatus(422);
        $this->assertDatabaseMissing('system_settings',['key'=>'site.layout.header']);
    }
}
