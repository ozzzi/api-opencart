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
        Schema::create('leads', static function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->nullable();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('message')->nullable();
            $table->json('product_ids')->nullable();
            $table->enum('status', ['new', 'contacted', 'closed', 'spam'])->default('new');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->foreign('session_id')->references('id')->on('chat_sessions')->nullOnDelete();
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
