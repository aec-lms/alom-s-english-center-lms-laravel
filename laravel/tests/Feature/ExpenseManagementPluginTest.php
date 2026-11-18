<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseManagementPluginTest extends TestCase
{
    public function test_expense_plugin_installs_without_branch_or_payment_plugins_and_admin_can_record_expense(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['expense-management']);
        $admin = $this->createUserWithRole('admin');

        $this->assertTrue(app(PluginManager::class)->isEnabled('expense-management'));
        $this->assertFalse(app(PluginManager::class)->isInstalled('branch-management'));
        $this->assertFalse(app(PluginManager::class)->isInstalled('payment-management'));
        $this->assertTrue(Schema::hasTable('expenses'));

        $this->actingAs($admin)->post('/admin/expenses', [
            'category' => 'Utilities',
            'title' => 'Head office electricity',
            'amount' => 1850,
            'payment_method' => 'bank',
            'expense_date' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('expenses', [
            'title' => 'Head office electricity',
            'branch_id' => null,
            'status' => 'approved',
            'created_by' => $admin->id,
        ]);
    }

    public function test_expense_receipts_are_private_and_finance_summary_uses_approved_expenses(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['expense-management']);
        Storage::fake('local');
        $admin = $this->createUserWithRole('admin');

        $file = UploadedFile::fake()->create('electricity-receipt.pdf', 200, 'application/pdf');
        $this->actingAs($admin)->post('/admin/expenses', [
            'category' => 'Utilities',
            'title' => 'Office electricity',
            'amount' => 200,
            'payment_method' => 'cash',
            'expense_date' => now()->toDateString(),
            'receipt' => $file,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $expense = DB::table('expenses')->where('title', 'Office electricity')->first();
        $this->assertNotNull($expense->receipt_path);
        Storage::disk('local')->assertExists($expense->receipt_path);
        Storage::disk('public')->assertMissing($expense->receipt_path);

        $this->actingAs($admin)->get("/admin/expenses/{$expense->id}/receipt")->assertOk();

        $student = $this->createUserWithRole('student');
        $this->actingAs($student)->get("/admin/expenses/{$expense->id}/receipt")->assertForbidden();

        $this->actingAs($admin)->get('/admin/expenses/finance-summary')
            ->assertOk()
            ->assertSee('Approved Expenses')
            ->assertSee('৳200.00')
            ->assertSee('Net Balance');
    }

    public function test_expense_delete_uses_recycle_bin_and_admin_can_restore_it(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['expense-management']);
        $admin = $this->createUserWithRole('admin');
        $id = DB::table('expenses')->insertGetId([
            'created_by' => $admin->id,
            'category' => 'Rent',
            'title' => 'Office rent',
            'amount' => 1000,
            'payment_method' => 'bank',
            'expense_date' => now()->toDateString(),
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->delete("/admin/expenses/{$id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSoftDeleted('expenses', ['id' => $id]);

        $this->actingAs($admin)->post("/admin/expenses/recycle-bin/{$id}/restore")
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('expenses', ['id' => $id, 'deleted_at' => null]);
    }

    public function test_branch_manager_is_confined_to_active_assigned_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'expense-management']);
        $admin = $this->createUserWithRole('admin');
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'DHK-01', 'name' => 'Dhaka Branch', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherBranchId = DB::table('branches')->insertGetId([
            'code' => 'SYL-01', 'name' => 'Sylhet Branch', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $roleId = DB::table('roles')->where('slug', 'branch_manager')->value('id');
        $this->assertNotNull($roleId);
        foreach (['expenses.view', 'expenses.manage'] as $slug) {
            $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
            $this->assertNotNull($permissionId);
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId, 'permission_id' => $permissionId,
            ]);
        }

        $manager = $this->createUserWithRole('branch_manager', $branchId);
        DB::table('expenses')->insert([
            'branch_id' => $branchId, 'created_by' => $admin->id, 'category' => 'Supplies',
            'title' => 'Local expense', 'amount' => 100, 'payment_method' => 'cash',
            'expense_date' => now()->toDateString(), 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('expenses')->insert([
            'branch_id' => $otherBranchId, 'created_by' => $admin->id, 'category' => 'Supplies',
            'title' => 'Remote expense', 'amount' => 900, 'payment_method' => 'cash',
            'expense_date' => now()->toDateString(), 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($manager)->get('/admin/expenses')
            ->assertOk()
            ->assertSee('Local expense')
            ->assertDontSee('Remote expense');

        $this->actingAs($manager)->post('/admin/expenses', [
            'branch_id' => $otherBranchId,
            'category' => 'Travel',
            'title' => 'Manager local travel',
            'amount' => 300,
            'payment_method' => 'cash',
            'expense_date' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('expenses', [
            'title' => 'Manager local travel',
            'branch_id' => $branchId,
            'status' => 'pending',
        ]);
    }
}
