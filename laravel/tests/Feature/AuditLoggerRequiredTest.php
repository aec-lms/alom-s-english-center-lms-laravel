<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Security\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class AuditLoggerRequiredTest extends TestCase
{
    public function test_optional_log_remains_a_no_op_when_audit_storage_is_missing(): void
    {
        Schema::shouldReceive('hasTable')
            ->with('audit_logs')
            ->andReturn(false);

        app(AuditLogger::class)->log('bootstrap.optional', 'system', 'pre-install');
        $this->assertTrue(true);
    }

    public function test_required_log_fails_closed_when_audit_storage_is_missing(): void
    {
        Schema::shouldReceive('hasTable')
            ->with('audit_logs')
            ->andReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required audit logging is unavailable');

        app(AuditLogger::class)->logRequired('security.permission_changed', 'user', '42');
    }
    public function test_required_log_propagates_audit_insert_failures(): void
    {
        Schema::shouldReceive('hasTable')
            ->with('audit_logs')
            ->andReturn(true);

        DB::listen(function ($query): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'audit_logs')) {
                throw new RuntimeException('Injected audit insert failure.');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Injected audit insert failure');

        app(AuditLogger::class)->logRequired('payment.settled', 'payment_transaction', '42');
    }

}
