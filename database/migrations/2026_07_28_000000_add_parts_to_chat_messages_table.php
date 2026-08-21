<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Stores the ordered parts of an assistant reply — Markdown prose interleaved
     * with typed product blocks. `content` keeps holding the prose alone, so
     * summarization and full-text search are unaffected.
     */
    public function up(): void
    {
        Schema::table('chat_messages', static function (Blueprint $table) {
            $table->json('parts')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', static function (Blueprint $table) {
            $table->dropColumn('parts');
        });
    }
};
