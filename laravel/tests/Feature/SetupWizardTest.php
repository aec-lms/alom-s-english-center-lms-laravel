<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    private function setupAdmin(): User
    {
        $admin = User::query()->create(['name' => 'Wizard Admin', 'email' => 'wizard-admin@example.test', 'password' => 'password', 'is_active' => true]);
        $role = Role::query()->firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator', 'is_system' => true]);
        $permission = Permission::query()->firstOrCreate(['slug' => 'core.settings.manage'], ['name' => 'Manage Core System Settings', 'module' => 'core']);
        $dashboard = Permission::query()->firstOrCreate(['slug' => 'core.dashboard.view'], ['name' => 'View Core Dashboard', 'module' => 'core']);
        $role->permissions()->syncWithoutDetaching([$permission->id, $dashboard->id]);
        $admin->roles()->syncWithoutDetaching([$role->id]);
        return $admin;
    }

    public function test_setup_wizard_saves_each_step_and_can_resume_later(): void
    {
        $this->markSystemInstalled();
        $admin = $this->setupAdmin();

        $this->actingAs($admin)->get(route('admin.setup-wizard.index'))->assertOk()->assertSee('LMS Setup Wizard');
        $this->actingAs($admin)->post(route('admin.setup-wizard.save', 'institution'), [
            'institution_name' => 'Alom English Center', 'institution_website' => 'https://academy.example',
            'institution_country' => 'BD', 'institution_locale' => 'bn-BD',
            'institution_timezone' => 'Asia/Dhaka', 'institution_currency' => 'BDT',
        ])->assertRedirect(route('admin.setup-wizard.index', ['step' => 'branding']));
        $this->assertDatabaseHas('system_settings', ['key' => 'institution.name', 'value' => 'Alom English Center']);
        $this->assertDatabaseHas('system_settings', ['key' => 'setup.wizard.step.institution', 'value' => 'completed']);

        $this->actingAs($admin)->get(route('admin.setup-wizard.index', ['step' => 'academic']))->assertOk();
        $this->actingAs($admin)->post(route('admin.setup-wizard.save', 'academic'), [
            'academic_year_name' => '২০২৬ শিক্ষাবর্ষ',
            'academic_year_start' => '2026-01-01', 'academic_year_end' => '2026-12-31',
        ])->assertRedirect(route('admin.setup-wizard.index', ['step' => 'payments']));
        $this->assertDatabaseHas('system_settings', ['key' => 'academic.current_year_name', 'value' => '২০২৬ শিক্ষাবর্ষ']);
    }

    public function test_payment_secrets_are_encrypted_at_rest(): void
    {
        $this->markSystemInstalled();
        $admin = $this->setupAdmin();
        $this->actingAs($admin)->post(route('admin.setup-wizard.save', 'payments'), [
            'bkash_enabled' => '1', 'bkash_number' => '01700000000', 'bkash_secret' => 'merchant-secret',
            'nagad_enabled' => '1', 'nagad_number' => '01800000000', 'nagad_secret' => 'nagad-merchant-secret',
            'manual_enabled' => '1', 'manual_instructions' => 'Send payment and share transaction ID.',
        ])->assertRedirect(route('admin.setup-wizard.index', ['step' => 'review']));
        $stored = SystemSetting::query()->where('key', 'setup.payment.bkash.secret')->value('value');
        $this->assertNotSame('merchant-secret', $stored);
        $this->assertSame('merchant-secret', Crypt::decryptString($stored));
        $this->assertDatabaseHas('system_settings', ['key' => 'setup.payment.bkash.enabled', 'value' => '1']);
        $nagadStored = SystemSetting::query()->where('key', 'setup.payment.nagad.secret')->value('value');
        $this->assertNotSame('nagad-merchant-secret', $nagadStored);
        $this->assertSame('nagad-merchant-secret', Crypt::decryptString($nagadStored));

        $html = $this->actingAs($admin)->get(route('admin.setup-wizard.index', ['step' => 'payments']))
            ->assertOk()
            ->assertSee('নগদ')
            ->assertSee('আগের secret নিরাপদে সংরক্ষিত আছে')
            ->getContent();
        $this->assertStringNotContainsString('merchant-secret', $html);
    }

    public function test_setup_wizard_completion_requires_all_steps_and_persists_completion_flag(): void
    {
        $this->markSystemInstalled();
        $admin = $this->setupAdmin();

        $this->actingAs($admin)->post(route('admin.setup-wizard.complete'))
            ->assertRedirect(route('admin.setup-wizard.index'))
            ->assertSessionHasErrors('setup');

        $this->actingAs($admin)->post(route('admin.setup-wizard.save', 'institution'), [
            'institution_name' => 'Aloms English Center',
            'institution_country' => 'BD',
            'institution_locale' => 'bn',
            'institution_timezone' => 'Asia/Dhaka',
            'institution_currency' => 'BDT',
        ])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.setup-wizard.save', 'branding'), [
            'primary_color' => '#0057FF',
            'secondary_color' => '#022D41',
            'accent_color' => '#38BDF8',
        ])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.setup-wizard.save', 'academic'), [
            'academic_year_name' => '২০২৬ শিক্ষাবর্ষ',
            'academic_year_start' => '2026-01-01',
            'academic_year_end' => '2026-12-31',
        ])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.setup-wizard.save', 'payments'), [
            'manual_enabled' => '1',
            'manual_instructions' => 'Contact the office for payment instructions.',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.setup-wizard.complete'))
            ->assertRedirect(route('admin.dashboard'));
        $this->assertDatabaseHas('system_settings', ['key' => 'setup.wizard.completed', 'value' => '1']);
    }

    public function test_dashboard_reminds_admin_when_setup_is_incomplete(): void
    {
        $this->markSystemInstalled();
        $admin = $this->setupAdmin();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('আপনার LMS Setup এখনো সম্পন্ন হয়নি');
    }

    public function test_setup_wizard_requires_settings_permission(): void
    {
        $this->markSystemInstalled();
        $teacher = User::query()->create(['name' => 'Teacher', 'email' => 'wizard-teacher@example.test', 'password' => 'password', 'is_active' => true]);
        $this->actingAs($teacher)->get(route('admin.setup-wizard.index'))->assertForbidden();
    }
}
