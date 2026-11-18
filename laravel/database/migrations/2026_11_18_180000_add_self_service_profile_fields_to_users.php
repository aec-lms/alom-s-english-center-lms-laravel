<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 40)->nullable()->unique();
            $table->string('display_name', 120)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('headline', 160)->nullable();
            $table->text('bio')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('website', 2048)->nullable();
            $table->json('social_links')->nullable();
            $table->boolean('profile_is_public')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn([
                'username', 'display_name', 'avatar_path', 'cover_path',
                'headline', 'bio', 'location', 'website', 'social_links',
                'profile_is_public',
            ]);
        });
    }
};
