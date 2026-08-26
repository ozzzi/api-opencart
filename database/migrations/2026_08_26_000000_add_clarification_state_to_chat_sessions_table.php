<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Holds the state of the current product-picking task: how many clarifying
     * questions have already been asked, whether the customer opted out of them,
     * and which query the counter belongs to.
     *
     * Kept in the database rather than Redis so the round counter cannot be lost
     * mid-conversation by a cache flush, and so a manager reviewing the dialogue
     * in the admin panel can see why the bot asked — or did not ask — a question.
     */
    public function up(): void
    {
        Schema::table('chat_sessions', static function (Blueprint $table) {
            $table->json('clarification_state')->nullable()->after('context_summary');
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', static function (Blueprint $table) {
            $table->dropColumn('clarification_state');
        });
    }
};
