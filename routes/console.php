<?php

declare(strict_types=1);

use App\Console\Commands\Chat\CatalogSync;
use App\Jobs\BudgetThresholdAlertJob;
use App\Jobs\DailyUsageStatsAggregatorJob;
use App\Jobs\PurgeExpiredChatsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Incremental catalog sync — skips if a previous run is still going
Schedule::command(CatalogSync::class)->everyFiveMinutes()->withoutOverlapping();

// Aggregate yesterday's LLM usage into daily_usage_stats
Schedule::job(new DailyUsageStatsAggregatorJob)->dailyAt('01:00');

// GDPR retention: delete / anonymise expired chat sessions
Schedule::job(new PurgeExpiredChatsJob)->daily();

// Alert admin when daily LLM budget crosses the configured threshold
Schedule::job(new BudgetThresholdAlertJob)->hourly();
