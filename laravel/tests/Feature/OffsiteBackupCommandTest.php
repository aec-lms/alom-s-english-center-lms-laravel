<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class OffsiteBackupCommandTest extends TestCase
{
    public function test_offsite_command_fails_closed_when_private_storage_credentials_are_missing(): void
    {
        config([
            'lms.backup_offsite.endpoint' => null,
            'lms.backup_offsite.region' => null,
            'lms.backup_offsite.bucket' => null,
            'lms.backup_offsite.access_key' => null,
            'lms.backup_offsite.secret_key' => null,
        ]);

        $this->artisan('lms:backup:offsite')
            ->assertExitCode(1)
            ->expectsOutputToContain('Off-site backup is not configured');
    }
}
