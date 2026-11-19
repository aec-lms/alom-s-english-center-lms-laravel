<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('boost_campaign_events')) {
            Schema::create('boost_campaign_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('campaign_id')->constrained('boost_campaigns')->cascadeOnDelete();
                $table->string('event_type', 12);
                $table->char('visitor_hash', 64);
                $table->timestamp('occurred_at');
                $table->unique(['campaign_id', 'event_type', 'visitor_hash'], 'boost_event_visitor_unique');
                $table->index(['campaign_id', 'occurred_at'], 'boost_event_campaign_time_index');
            });
        }
    }

    public function down(): void
    {
        // Analytics are retained with plugin data according to core uninstall policy.
    }
};
