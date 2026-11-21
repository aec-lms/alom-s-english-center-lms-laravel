<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Security\AuditLogger;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class AuditLoggerRequiredTest extends TestCase
{
    public function test_optional_log_remains_a_no_op_when_audit_storage_is_missing(): void
    {
        Schema::partialMock()
            ->shouldReceive('hasTable')
            ->with('audit_logs')
            ->andReturn(false);

        app(AuditLogger::class)->log('bootstrap.optional', 'system', 'pre-install');
        $this->assertTrue(true);
    }

    public function test_required_log_fails_closed_when_audit_storage_is_missing(): void
    {
        Schema::partialMock()
            ->shouldReceive('hasTable')
            ->with('audit_logs')
            ->andReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required audit logging is unavailable');

        app(AuditLogger::class)->logRequired('security.permission_changed', 'user', '42');
    }
}
