<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeeBranchIsolationTest extends TestCase
{
    public function test_unassigned_branch_manager_cannot_list_or_create_fee_invoices(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'fees-management']);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = DB::table('permissions')->where('slug', 'fees.manage')->pluck('id');
        $this->assertCount(1, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)->get('/admin/fees')->assertForbidden();
        $this->actingAs($manager)->post('/admin/fees/invoices', [
            'payer_name' => 'Unassigned Manager',
            'payer_email' => 'unassigned@example.test',
            'payer_phone' => '01700000000',
            'subtotal_amount' => 1000,
        ])->assertForbidden();

        $this->assertDatabaseMissing('fee_invoices', [
            'payer_email' => 'unassigned@example.test',
        ]);
    }
}
