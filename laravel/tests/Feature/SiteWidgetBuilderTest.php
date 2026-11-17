<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Appearance\SiteWidgetManager;
use Tests\TestCase;

class SiteWidgetBuilderTest extends TestCase
{
    public function test_admin_can_manage_safe_course_sidebar_widgets_and_public_page_renders_them(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'site-widgets-admin@aec.test');

        $this->actingAs($admin)->get(route('admin.site-widgets.index'))
            ->assertOk()->assertSee('Widget Area &amp; Sidebar Builder');

        $this->actingAs($admin)->post(route('admin.site-widgets.update'), [
            'widgets' => [
                ['type'=>'notice','area'=>'course-sidebar','title'=>'Admission Notice','content'=>'New batch starts soon.','visible'=>'1','order'=>'10'],
                ['type'=>'cta','area'=>'course-sidebar','title'=>'Contact team','content'=>'Ask us about the course.','label'=>'Contact us','url'=>'/contact-us','visible'=>'1','order'=>'20'],
                ['type'=>'text','area'=>'site-sidebar','title'=>'Unsafe HTML test','content'=>'<script>alert(1)</script>','visible'=>'1','order'=>'10'],
            ],
        ])->assertRedirect(route('admin.site-widgets.index'));

        $widgets = app(SiteWidgetManager::class)->all();
        $this->assertCount(3, $widgets);
        $this->assertSame('/contact-us', $widgets[1]['url']);
        $this->assertDatabaseHas('audit_logs', ['action'=>'site.widgets.updated']);

        $this->view('public.partials.widget-area', ['area'=>'course-sidebar'])
            ->assertSee('Admission Notice')
            ->assertSee('New batch starts soon.')
            ->assertDontSee('<script>', false);
    }

    public function test_widget_manager_rejects_unsafe_urls_and_does_not_execute_markup(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'site-widgets-safe@aec.test');
        $this->actingAs($admin)->post(route('admin.site-widgets.update'), [
            'widgets' => [
                ['type'=>'cta','area'=>'course-sidebar','title'=>'Bad CTA','content'=>'x','label'=>'Click','url'=>'javascript:alert(1)','visible'=>'1','order'=>'1'],
            ],
        ])->assertRedirect(route('admin.site-widgets.index'));

        $this->assertSame([], app(SiteWidgetManager::class)->all());
    }
}
