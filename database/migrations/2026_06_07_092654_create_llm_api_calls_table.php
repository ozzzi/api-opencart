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
        Schema::create('llm_api_calls', static function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('model', 100);
            $table->enum('type', ['chat', 'embedding']);
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->integer('latency_ms')->nullable();
            $table->string('provider', 50);
            $table->boolean('success')->default(true);
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('session_id')->references('id')->on('chat_sessions')->nullOnDelete();
            $table->foreign('message_id')->references('id')->on('chat_messages')->nullOnDelete();
            $table->index(['session_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llm_api_calls');
    }
};
