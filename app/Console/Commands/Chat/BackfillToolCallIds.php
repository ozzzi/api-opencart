<?php

declare(strict_types=1);

namespace App\Console\Commands\Chat;

use App\Models\Bot\ChatMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('chat:backfill-tool-call-ids')]
#[Description('Backfill tool_call_id on legacy chat_messages rows written before the column existed, pairing them positionally with the preceding assistant tool_calls announcement.')]
final class BackfillToolCallIds extends Command
{
    public function handle(): int
    {
        $sessionIds = ChatMessage::query()
            ->where('role', 'tool')
            ->whereNull('tool_call_id')
            ->distinct()
            ->pluck('session_id');

        if ($sessionIds->isEmpty()) {
            $this->info('No legacy rows to backfill.');

            return self::SUCCESS;
        }

        $fixed = 0;
        $skipped = 0;

        foreach ($sessionIds as $sessionId) {
            $messages = ChatMessage::query()
                ->where('session_id', $sessionId)
                ->orderBy('id')
                ->get(['id', 'role', 'tool_calls', 'tool_call_id']);

            /** @var list<string> $pendingIds */
            $pendingIds = [];

            foreach ($messages as $message) {
                if ($message->role === 'assistant' && $message->tool_calls !== null) {
                    foreach ($message->tool_calls as $toolCall) {
                        $pendingIds[] = $toolCall['id'];
                    }

                    continue;
                }

                if ($message->role !== 'tool' || $message->tool_call_id !== null) {
                    continue;
                }

                $id = array_shift($pendingIds);

                if ($id === null) {
                    $skipped++;

                    continue;
                }

                $message->forceFill(['tool_call_id' => $id])->save();
                $fixed++;
            }
        }

        $this->info("Backfilled {$fixed} row(s) across {$sessionIds->count()} session(s).");

        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} row(s) with no matching tool_calls announcement.");
        }

        return self::SUCCESS;
    }
}
