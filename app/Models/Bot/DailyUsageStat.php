<?php

declare(strict_types=1);

namespace App\Models\Bot;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property Carbon $date
 * @property int $total_sessions
 * @property int $total_messages
 * @property float $total_cost_usd
 * @property int $avg_latency_ms
 * @property int $escalations
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class DailyUsageStat extends Model
{
    protected $table = 'daily_usage_stats';

    protected $fillable = [
        'date',
        'total_sessions',
        'total_messages',
        'total_cost_usd',
        'avg_latency_ms',
        'escalations',
    ];

    protected function casts(): array
    {
        return [
            'total_cost_usd' => 'decimal:4',
        ];
    }
}
