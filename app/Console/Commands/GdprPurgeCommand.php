<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Bot\ChatSession;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('chat:gdpr-purge {session_id : UUID of the session to anonymise or delete}')]
#[Description('Anonymise or delete a chat session on GDPR subject-access request.')]
final class GdprPurgeCommand extends Command
{
    public function handle(): int
    {
        $sessionId = (string) $this->argument('session_id');

        $session = ChatSession::find($sessionId);

        if ($session === null) {
            $this->error("Session [{$sessionId}] not found.");

            return self::FAILURE;
        }

        if ($session->lead !== null) {
            // Session has a lead — anonymise PII but keep the record
            $session->update([
                'ip_address' => '0.0.0.0',
                'user_agent' => 'anonymized',
            ]);

            $this->info("Session [{$sessionId}] anonymised (lead retained).");
        } else {
            $session->delete();
            $this->info("Session [{$sessionId}] deleted.");
        }

        return self::SUCCESS;
    }
}
