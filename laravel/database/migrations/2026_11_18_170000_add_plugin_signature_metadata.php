<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table): void {
            $table->string('signature_key_id', 80)->nullable()->after('installed_at');
            $table->string('signature_algorithm', 20)->nullable()->after('signature_key_id');
            $table->string('signature_checksums_sha256', 64)->nullable()->after('signature_algorithm');
            $table->timestamp('signature_verified_at')->nullable()->after('signature_checksums_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table): void {
            $table->dropColumn([
                'signature_key_id',
                'signature_algorithm',
                'signature_checksums_sha256',
                'signature_verified_at',
            ]);
        });
    }
};
