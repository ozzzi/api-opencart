<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bot\ChatSession;
use App\Settings\BotPrivacySettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
#[Backoff([30, 60, 120])]
#[Timeout(300)]
final class PurgeExpiredChatsJob implements ShouldQueue
{
    use Queueable;

    private const int CHUNK_SIZE = 500;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(BotPrivacySettings $privacySettings): void
    {
        $cutoff = now()->subDays($privacySettings->dataRetentionDays);

        // Sessions with no associated lead: delete entirely
        ChatSession::query()
            ->whereDoesntHave('lead')
            ->where('created_at', '<', $cutoff)
            ->chunkById(self::CHUNK_SIZE, function ($sessions): void {
                $ids = $sessions->pluck('id')->all();
                ChatSession::whereIn('id', $ids)->delete();
            });

        // Sessions with a lead: anonymise PII, keep the record for the lead
        ChatSession::query()
            ->whereHas('lead')
            ->where('created_at', '<', $cutoff)
            ->chunkById(self::CHUNK_SIZE, function ($sessions): void {
                $ids = $sessions->pluck('id')->all();
                ChatSession::whereIn('id', $ids)->update([
                    'ip_address' => '0.0.0.0',
                    'user_agent' => 'anonymized',
                ]);
            });
    }
}
