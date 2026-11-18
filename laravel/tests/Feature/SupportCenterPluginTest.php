<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupportCenterPluginTest extends TestCase
{
    public function test_support_center_installs_without_branch_management(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['support-center']);

        $this->assertTrue(app(PluginManager::class)->isEnabled('support-center'));
        $this->assertFalse(app(PluginManager::class)->isInstalled('branch-management'));
        $this->assertTrue(Schema::hasTable('support_tickets'));
        $this->assertTrue(Schema::hasTable('support_ticket_messages'));
    }

    public function test_student_can_create_view_and_reply_to_their_own_ticket_via_api(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['support-center']);
        $student = $this->createUserWithRole('student');
        $token = $student->createToken('support-test')->plainTextToken;

        $created = $this->withToken($token)->postJson('/api/v1/my/support-tickets', [
            'subject' => 'Cannot open lesson video',
            'message' => 'The video player shows an error on my phone.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.subject', 'Cannot open lesson video');

        $ticketId = $created->json('data.id');
        $this->withToken($token)->getJson('/api/v1/my/support-tickets')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ticketId);

        $this->withToken($token)->getJson('/api/v1/my/support-tickets/'.$ticketId)
            ->assertOk()
            ->assertJsonPath('data.messages.0.sender_role', 'student');

        $this->withToken($token)->postJson('/api/v1/my/support-tickets/'.$ticketId.'/messages', [
            'message' => 'I can share more details if needed.',
        ])->assertCreated()
            ->assertJsonPath('data.sender_role', 'student');

        $this->assertDatabaseCount('support_ticket_messages', 2);
        $this->assertDatabaseHas('support_tickets', ['id' => $ticketId, 'user_id' => $student->id]);
    }

    public function test_student_cannot_read_or_reply_to_another_students_ticket(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['support-center']);
        $owner = $this->createUserWithRole('student');
        $other = $this->createUserWithRole('student');
        $ticketId = DB::table('support_tickets')->insertGetId([
            'user_id' => $owner->id,
            'subject' => 'Private ticket',
            'status' => 'open',
            'last_message_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = $other->createToken('support-other')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/my/support-tickets/'.$ticketId)->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/my/support-tickets/'.$ticketId.'/messages', [
            'message' => 'Trying to access another ticket.',
        ])->assertNotFound();
    }

    public function test_closed_ticket_rejects_student_replies(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['support-center']);
        $student = $this->createUserWithRole('student');
        $ticketId = DB::table('support_tickets')->insertGetId([
            'user_id' => $student->id,
            'subject' => 'Closed support request',
            'status' => 'closed',
            'closed_at' => now(),
            'last_message_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = $student->createToken('support-closed')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/my/support-tickets/'.$ticketId.'/messages', [
            'message' => 'Please reopen this request.',
        ])->assertStatus(409);
        $this->assertDatabaseCount('support_ticket_messages', 0);
    }

    public function test_admin_can_reply_and_close_student_ticket_from_web_dashboard(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['support-center']);
        $student = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $ticketId = DB::table('support_tickets')->insertGetId([
            'user_id' => $student->id,
            'subject' => 'Need help with payment',
            'status' => 'open',
            'last_message_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('support_ticket_messages')->insert([
            'ticket_id' => $ticketId,
            'sender_id' => $student->id,
            'sender_role' => 'student',
            'body' => 'My payment reference is not showing.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get("/admin/support-tickets/{$ticketId}")
            ->assertOk()
            ->assertSee('My payment reference is not showing.');

        $this->actingAs($admin)->post("/admin/support-tickets/{$ticketId}/messages", [
            'message' => 'We have checked your payment. Please refresh your account.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('support_tickets', ['id' => $ticketId, 'status' => 'in_progress']);
        $this->assertDatabaseHas('support_ticket_messages', [
            'ticket_id' => $ticketId,
            'sender_id' => $admin->id,
            'sender_role' => 'admin',
        ]);

        $this->actingAs($admin)->patch("/admin/support-tickets/{$ticketId}/status", ['status' => 'closed'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('support_tickets', ['id' => $ticketId, 'status' => 'closed']);
    }
}
