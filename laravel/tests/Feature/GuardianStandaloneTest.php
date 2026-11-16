<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GuardianStandaloneTest extends TestCase
{
    public function test_guardian_management_installs_and_creates_profile_without_student_or_branch_plugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['guardian-management']);
        $admin = $this->createUserWithRole('admin');

        $this->assertFalse(app(PluginManager::class)->isInstalled('student-management'));
        $this->assertFalse(app(PluginManager::class)->isInstalled('branch-management'));

        $this->actingAs($admin)->get('/admin/guardians')
            ->assertOk()
            ->assertSee('Student integration not enabled');

        $this->actingAs($admin)->post('/admin/guardians', [
            'name' => 'Standalone Guardian',
            'email' => 'guardian.standalone@aec.test',
            'phone' => '01712345678',
            'password' => 'StrongGuardianPassword123!',
            'relationship' => 'Parent',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('guardians', [
            'relationship' => 'Parent',
            'branch_id' => null,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'guardian.standalone@aec.test',
            'branch_id' => null,
        ]);
        $this->assertFalse(Schema::hasTable('guardian_student'));
    }
}
