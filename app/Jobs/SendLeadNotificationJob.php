<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bot\Lead;
use App\Services\Chat\Contracts\LeadNotifierInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
#[Backoff([10, 30, 60])]
final class SendLeadNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $leadId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(LeadNotifierInterface $notifier): void
    {
        $lead = Lead::findOrFail($this->leadId);

        $notifier->notify($lead);
    }
}
