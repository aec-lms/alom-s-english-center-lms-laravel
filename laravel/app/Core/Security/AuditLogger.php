<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AuditLogger
{
    /**
     * Best-effort telemetry for bootstrap paths where audit storage may not exist.
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
     * Durable evidence for security, financial and destructive operations.
     * Call inside the business transaction so failure rolls the operation back.
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
        $entry = new AuditLog([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => request()?->ip(),
            'metadata' => $metadata,
        ]);

        // Model events can veto save(); required evidence must be verified.
        if (!$entry->save()) {
            throw new RuntimeException(
                'Required audit log entry could not be persisted; the operation cannot be completed safely.'
            );
        }
    }
}
