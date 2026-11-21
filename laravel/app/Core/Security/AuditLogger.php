<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class AuditLogger
{
    public function log(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = [],
        ?int $userId = null,
    ): void {
        // Optional telemetry remains safe during pre-install/bootstrap phases.
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        $this->persist($action, $entityType, $entityId, $metadata, $userId);
    }

    /**
     * Persist an audit event that is part of a security, financial or
     * compliance-sensitive state transition. Missing storage and insert errors
     * must propagate so the caller's surrounding transaction can roll back.
     */
    public function logRequired(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = [],
        ?int $userId = null,
    ): void {
        if (!Schema::hasTable('audit_logs')) {
            throw new \\RuntimeException(
                'Required audit logging is unavailable: the audit_logs table does not exist.'
            );
        }

        $this->persist($action, $entityType, $entityId, $metadata, $userId);
    }

    private function persist(
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
