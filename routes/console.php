<?php

declare(strict_types=1);

use App\Console\Commands\Chat\CatalogSync;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Chat bot scheduled tasks (Phase 6 will add the rest).
Schedule::command(CatalogSync::class)->everyFiveMinutes()->withoutOverlapping();
