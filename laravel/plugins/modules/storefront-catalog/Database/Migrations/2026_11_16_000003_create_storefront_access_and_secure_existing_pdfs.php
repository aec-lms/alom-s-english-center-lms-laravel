<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('storefront_item_access')) {
            Schema::create('storefront_item_access', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->id();
                $table->foreignId('storefront_item_id')->constrained('storefront_items')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('payment_transaction_id')->nullable()->index();
                $table->timestamp('granted_at')->useCurrent();
                $table->timestamps();
                $table->unique(['storefront_item_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('storefront_items') || !Schema::hasColumn('storefront_items', 'download_url')) {
            return;
        }

        // Move legacy locally uploaded PDFs out of the public web root before enabling protected downloads.
        foreach (DB::table('storefront_items')->whereNotNull('download_url')->get(['id', 'download_url']) as $item) {
            $url = (string) $item->download_url;
            if (!str_starts_with($url, '/uploads/storefront/pdfs/')) {
                continue;
            }
            $source = public_path(ltrim($url, '/'));
            if (!File::isFile($source)) {
                continue;
            }
            $name = (string) \Illuminate\Support\Str::uuid() . '.pdf';
            $saved = Storage::disk('local')->putFileAs('digital-products', new \Illuminate\Http\File($source), $name);
            if ($saved) {
                DB::table('storefront_items')->where('id', $item->id)->update(['download_url' => 'private:' . $saved]);
                File::delete($source);
            }
        }
    }

    public function down(): void
    {
        // Keep purchased digital content and access records intact on rollback.
    }
};