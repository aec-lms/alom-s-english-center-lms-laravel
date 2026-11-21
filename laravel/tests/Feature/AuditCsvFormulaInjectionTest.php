<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Tests\TestCase;

class AuditCsvFormulaInjectionTest extends TestCase
{
    public function test_audit_csv_escapes_formula_prefixes_in_untrusted_fields(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['reports-analytics']);
        $admin = $this->createUserWithRole('admin', null, 'audit-csv-admin@aec.test');

        AuditLog::query()->create([
            'user_id' => $admin->id,
            'action' => '=1+1',
            'entity_type' => '+cmd',
            'entity_id' => '-1+1',
            'ip_address' => "\t@SUM(1,2)",
            'metadata' => [],
        ]);

        $response = $this->actingAs($admin)
            ->get(route('plugins.reports.audit.export'))
            ->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'+cmd", $csv);
        $this->assertStringContainsString("'-1+1", $csv);
        $this->assertStringContainsString("'\t@SUM(1,2)", $csv);
    }
}
