<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Security\AuditLogger;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class RequiredAuditLoggerTest extends TestCase
{
    public function test_optional_logging_remains_a_no_op_when_audit_storage_is_missing(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('audit_logs')->andReturn(false);

        app(AuditLogger::class)->log('bootstrap.optional');

        $this->assertTrue(true);
    }

    public function test_required_logging_fails_closed_when_audit_storage_is_missing(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('audit_logs')->andReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required audit log storage is unavailable');

        app(AuditLogger::class)->logRequired('payment.settled', 'payment', '123');
    }

    public function test_required_logging_persists_actor_and_metadata(): void
    {
        $actor = User::query()->create([
            'name' => 'Audit actor',
            'email' => 'audit-' . bin2hex(random_bytes(8)) . '@aec.test',
            'password' => Hash::make('AuditTestPassword!2026'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        app(AuditLogger::class)->logRequired(
            'security.test_event',
            'user',
            (string) $actor->id,
            ['source' => 'automated-test'],
            $actor->id,
        );

        $entry = AuditLog::query()->where('action', 'security.test_event')->firstOrFail();

        $this->assertSame($actor->id, $entry->user_id);
        $this->assertSame('user', $entry->entity_type);
        $this->assertSame((string) $actor->id, $entry->entity_id);
        $this->assertSame(['source' => 'automated-test'], $entry->metadata);
    }

    public function test_required_logging_fails_when_a_model_event_vetoes_persistence(): void
    {
        AuditLog::saving(static fn (): bool => false);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Required audit log entry could not be persisted');

            app(AuditLogger::class)->logRequired('security.vetoed_event', 'user', '123');
        } finally {
            AuditLog::flushEventListeners();
        }
    }
}
