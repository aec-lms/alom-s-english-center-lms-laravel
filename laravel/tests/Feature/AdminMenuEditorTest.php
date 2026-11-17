<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class AdminMenuEditorTest extends TestCase
{
    public function test_admin_can_reorganize_and_hide_plugin_menu_entries(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracing']);
        $admin = $this->createUserWithRole('admin', null, 'menu-editor@aec.test');

        $this->actingAs($admin)->get(route('admin.menu-editor.index'))
            ->assertOk()
            ->assertSee('Admin Menu Editor')
            ->assertSee('Student Activity Trace');

        $this->actingAs($admin)->post(route('admin.menu-editor.update'), [
            'items' => [[
                'key' => 'student-tracing|plugins.student-tracing.index',
                'group' => 'Student Services',
                'order' => 5,
                'visibility' => 'hide',
            ]],
        ])->assertRedirect(route('admin.menu-editor.index'));

        $this->assertDatabaseHas('system_settings', [
            'key' => 'admin.navigation.menu',
            'updated_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.navigation.menu.updated']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Student Activity Trace');
    }

    public function test_admin_can_restore_a_hidden_plugin_menu_entry(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracing']);
        $admin = $this->createUserWithRole('admin', null, 'menu-restore@aec.test');

        $this->actingAs($admin)->post(route('admin.menu-editor.update'), [
            'items' => [[
                'key' => 'student-tracing|plugins.student-tracing.index',
                'group' => 'Student Services',
                'order' => 5,
                'visibility' => 'hide',
            ]],
        ])->assertRedirect();

        $this->actingAs($admin)->get(route('admin.menu-editor.index'))
            ->assertOk()
            ->assertSee('Student Activity Trace')
            ->assertSee('value="hide" selected', false);

        $this->actingAs($admin)->post(route('admin.menu-editor.update'), [
            'items' => [[
                'key' => 'student-tracing|plugins.student-tracing.index',
                'group' => 'Student Services',
                'order' => 5,
                'visibility' => 'show',
            ]],
        ])->assertRedirect();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Student Activity Trace');
    }

    public function test_menu_editor_rejects_routes_not_in_enabled_plugin_manifests(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracing']);
        $admin = $this->createUserWithRole('admin', null, 'menu-invalid@aec.test');

        $this->actingAs($admin)->post(route('admin.menu-editor.update'), [
            'items' => [[
                'key' => 'student-tracing|admin.users.destroy',
                'group' => 'Unsafe',
                'order' => 1,
                'visibility' => 'show',
            ]],
        ])->assertSessionHasErrors('items');
    }

    public function test_menu_editor_requires_core_settings_permission(): void
    {
        $this->markSystemInstalled();
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)->get(route('admin.menu-editor.index'))->assertForbidden();
        $this->actingAs($student)->post(route('admin.menu-editor.update'), ['items' => []])->assertForbidden();
    }
}
