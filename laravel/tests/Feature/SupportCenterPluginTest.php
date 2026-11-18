<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupportCenterPluginTest extends TestCase
{
    public function test_support_center_installs_without_branch_management(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['support-center']);

        $student = $this->createUserWithRole('student');
        $this->assertTrue(Schema::hasTable('support_tickets'));
        $this->assertTrue(Schema::hasTable('support_ticket_messages'));

        $this->actingAs($student)->post('/support/tickets', [
            'subject' => 'Course access issue',
            'message' => 'I cannot open my enrolled course.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('support_tickets', [
            'user_id' => $student->id,
            'subject' => 'Course access issue',
            'status' => 'open',
        ]);
    }

    public function test_student_can_only_view_and_reply_to_own_ticket(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['support-center']);
        $student = $this->createUserWithRole('student');
        $other = $this->createUserWithRole('student');
        $ticketId = DB::table('support_tickets')->insertGetId([
            'user_id' => $other->id,
            'subject' => 'Private request',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->get("/support/tickets/{$ticketId}")->assertNotFound();
        $this->actingAs($student)->post("/support/tickets/{$ticketId}/replies", [
            'message' => 'Trying to access another ticket.',
        ])->assertNotFound();
    }

    public function test_staff_reply_and_close_workflow_is_persisted(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['support-center']);
        $student = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $ticketId = DB::table('support_tickets')->insertGetId([
            'user_id' => $student->id,
            'subject' => 'Need help',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post("/admin/support-tickets/{$ticketId}/replies", [
            'message' => 'We are checking this issue.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('support_ticket_messages', [
            'ticket_id' => $ticketId,
            'sender_id' => $admin->id,
            'sender_role' => 'staff',
        ]);

        $this->actingAs($admin)->patch("/admin/support-tickets/{$ticketId}/status", [
            'status' => 'closed',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticketId,
            'status' => 'closed',
        ]);

        $this->actingAs($student)->post("/support/tickets/{$ticketId}/replies", [
            'message' => 'One more question.',
        ])->assertStatus(409);
    }

    public function test_branch_manager_cannot_read_another_branches_ticket(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'support-center']);
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'SUP-A', 'name' => 'Support A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'SUP-B', 'name' => 'Support B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student', $branchB);
        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $this->grantPermission($manager, 'support.tickets.manage');
        $ticketId = DB::table('support_tickets')->insertGetId([
            'user_id' => $student->id,
            'branch_id' => $branchB,
            'subject' => 'Branch private ticket',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)->get('/admin/support-tickets')->assertOk()->assertDontSee('Branch private ticket');
        $this->actingAs($manager)->get("/admin/support-tickets/{$ticketId}")->assertNotFound();
    }
}
