<?php

declare(strict_types=1);

namespace App\\Core\\Security;

use App\\Models\\AuditLog;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Schema;
use RuntimeException;

class AuditLogger
{
    /**
     * Best-effort telemetry for bootstrap paths where the audit table may not exist.
     */
    public function log(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = [],
        ?int $userId = null,
    ): void {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        $this->write($action, $entityType, $entityId, $metadata, $userId);
    }

    /**
     * Durable evidence required for security, financial and destructive operations.
     *
     * Call this inside the same database transaction as the operation whenever
     * the business state and its audit evidence must commit or roll back together.
     */
    public function logRequired(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = [],
        ?int $userId = null,
    ): void {
        if (!Schema::hasTable('audit_logs')) {
            throw new RuntimeException(
                'Required audit log storage is unavailable; the operation cannot be completed safely.'
            );
        }

        $this->write($action, $entityType, $entityId, $metadata, $userId);
    }

    private function write(
        string $action,
        ?string $entityType,
        ?string $entityId,
        array $metadata,
        ?int $userId,
    ): void {
        AuditLog::query()->create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => request()?->ip(),
            'metadata' => $metadata,
        ]);
    }
}
