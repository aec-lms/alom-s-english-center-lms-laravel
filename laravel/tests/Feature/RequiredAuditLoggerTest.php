<?php

declare(strict_types=1);

namespace Tests\\Feature;

use App\\Core\\Security\\AuditLogger;
use App\\Models\\AuditLog;
use App\\Models\\User;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use RuntimeException;
use Tests\\TestCase;

class RequiredAuditLoggerTest extends TestCase
{
    public function test_optional_bootstrap_logging_remains_a_no_op_without_audit_storage(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('audit_logs')->andReturn(false);

        app(AuditLogger::class)->log('bootstrap.optional');

        $this->assertTrue(true);
    }

    public function test_required_audit_fails_closed_when_audit_storage_is_missing(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('audit_logs')->andReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required audit log storage is unavailable');

        app(AuditLogger::class)->logRequired('payment.settled', 'payment', '123');
    }

    public function test_required_audit_persists_actor_and_metadata(): void
    {
        $actor = User::factory()->create();

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

    public function test_required_audit_write_failure_rolls_back_the_enclosing_business_transaction(): void
    {
        Schema::create('audit_required_atomicity_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });

        AuditLog::creating(static function (): void {
            throw new RuntimeException('forced audit persistence failure');
        });

        try {
            try {
                DB::transaction(function (): void {
                    DB::table('audit_required_atomicity_probe')->insert(['value' => 'must-rollback']);
                    app(AuditLogger::class)->logRequired('finance.payout', 'payout', 'failure-injection');
                });
                $this->fail('Required audit write failure should abort the business transaction.');
            } catch (RuntimeException $exception) {
                $this->assertSame('forced audit persistence failure', $exception->getMessage());
            }

            $this->assertDatabaseCount('audit_required_atomicity_probe', 0);
        } finally {
            AuditLog::flushEventListeners();
            Schema::dropIfExists('audit_required_atomicity_probe');
        }
    }
}
