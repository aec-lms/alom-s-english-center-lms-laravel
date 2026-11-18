<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('phonetic_audit_events')) {
            Schema::create('phonetic_audit_events', function (Blueprint $table): void {
                $table->id();
                $table->string('user_id', 128)->nullable()->index();
                $table->string('action', 120)->index();
                $table->string('entity_type', 80);
                $table->string('entity_id', 128);
                $table->string('ip_address', 45)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('phonetic_audit_events');
    }
};
