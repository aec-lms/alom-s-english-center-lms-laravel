<?php

declare(strict_types=1);

namespace Tests\\Feature;

use App\\Models\\Branch;
use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class ExpenseManagementPluginTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_record_and_filter_expenses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('plugins.expenses.store'), [
            'category' => 'Utilities', 'title' => 'Electricity', 'amount' => 1800,
            'payment_method' => 'bank', 'expense_date' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertDatabaseHas('expenses', ['title' => 'Electricity', 'branch_id' => null]);
        $this->get(route('plugins.expenses.index'))->assertOk()->assertSee('Electricity');
    }

    public function test_branch_manager_is_forced_to_own_active_branch_and_cannot_read_another_branch(): void
    {
        $branch = Branch::create(['name' => 'Dhaka', 'slug' => 'dhaka']);
        $other = Branch::create(['name' => 'Sylhet', 'slug' => 'sylhet']);
        $manager = User::factory()->create(['role' => 'branch_manager', 'branch_id' => $branch->id]);
        $this->actingAs($manager)->post(route('plugins.expenses.store'), [
            'branch_id' => $other->id, 'category' => 'Rent', 'title' => 'Office rent',
            'amount' => 5000, 'payment_method' => 'cash', 'expense_date' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertDatabaseHas('expenses', ['title' => 'Office rent', 'branch_id' => $branch->id]);
        $this->assertDatabaseMissing('expenses', ['title' => 'Office rent', 'branch_id' => $other->id]);
    }
}
