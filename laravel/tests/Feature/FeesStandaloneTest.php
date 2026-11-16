<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Tests\TestCase;

class FeesStandaloneTest extends TestCase
{
    public function test_fees_plugin_can_issue_invoice_to_standalone_payer_without_student_plugin(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management']);
        $admin = $this->createUserWithRole('admin');

        $this->assertFalse(app(PluginManager::class)->isInstalled('student-management'));
        $this->actingAs($admin)->get('/admin/fees')->assertOk();

        $this->actingAs($admin)->post('/admin/fees/invoices', [
            'payer_name' => 'Standalone Course Customer',
            'payer_email' => 'customer@aec.test',
            'subtotal_amount' => 2500,
            'discount_amount' => 250,
            'due_date' => now()->addDays(7)->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fee_invoices', [
            'student_id' => null,
            'payer_name' => 'Standalone Course Customer',
            'payer_email' => 'customer@aec.test',
            'branch_id' => null,
            'net_amount' => 2250,
        ]);
    }
}
