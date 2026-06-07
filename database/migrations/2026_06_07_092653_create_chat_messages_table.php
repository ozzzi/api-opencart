<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_messages', static function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id');
            $table->enum('role', ['user', 'assistant', 'tool', 'system']);
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_name', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->integer('tokens_used')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->boolean('fallback_used')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
            $table->index('session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
