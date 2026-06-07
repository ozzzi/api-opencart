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
        Schema::create('daily_usage_stats', static function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->integer('total_sessions')->default(0);
            $table->integer('total_messages')->default(0);
            $table->decimal('total_cost_usd', 10, 4)->default(0);
            $table->integer('avg_latency_ms')->default(0);
            $table->integer('escalations')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_usage_stats');
    }
};
