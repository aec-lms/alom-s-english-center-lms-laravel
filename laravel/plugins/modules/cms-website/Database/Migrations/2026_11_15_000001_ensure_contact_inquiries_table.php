<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('contact_inquiries')) {
            Schema::create('contact_inquiries', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('email', 180);
                $table->string('phone', 40)->nullable();
                $table->string('subject', 180);
                $table->text('message');
                $table->string('status', 24)->default('new');
                $table->timestamps();
            });

            return;
        }

        if (!Schema::hasColumn('contact_inquiries', 'phone')) {
            Schema::table('contact_inquiries', function (Blueprint $table): void {
                $table->string('phone', 40)->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        // Contact inquiries are retained when the CMS plugin is removed.
    }
};
