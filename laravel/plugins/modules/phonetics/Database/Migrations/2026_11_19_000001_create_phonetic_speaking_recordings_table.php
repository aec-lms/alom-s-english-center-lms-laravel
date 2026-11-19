<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('phonetic_speaking_recordings')) {
            return;
        }

        Schema::create('phonetic_speaking_recordings', function (Blueprint $table): void {
            $table->id();
            $table->string('lms_driver', 64)->index();
            $table->string('user_id', 128)->index();
            $table->foreignId('entry_id')->constrained('alom_english_center_phonetic_entries')->restrictOnDelete();
            $table->string('course_ref', 128);
            $table->string('storage_path', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('duration_seconds');
            $table->string('status', 20)->default('pending')->index();
            $table->string('reviewer_user_id', 128)->nullable()->index();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['lms_driver', 'course_ref', 'status', 'created_at'], 'phonetic_recordings_review_queue');
            $table->index(['user_id', 'entry_id', 'created_at'], 'phonetic_recordings_student_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phonetic_speaking_recordings');
    }
};
