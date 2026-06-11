<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Jobs\SendLeadNotificationJob;
use App\Models\Bot\Lead;
use App\Services\Chat\Contracts\LeadServiceInterface;

final class LeadService implements LeadServiceInterface
{
    /** @param array<string, mixed> $data */
    public function create(string $sessionId, array $data): Lead
    {
        /** @var Lead $lead */
        $lead = Lead::create([
            'session_id'  => $sessionId,
            'name'        => $data['name'] ?? null,
            'phone'       => $data['phone'] ?? null,
            'email'       => $data['email'] ?? null,
            'message'     => $data['message'] ?? null,
            'product_ids' => $data['product_ids'] ?? null,
            'status'      => 'new',
        ]);

        SendLeadNotificationJob::dispatch($lead->id);

        return $lead;
    }

    public function updateStatus(int $leadId, string $status): void
    {
        Lead::findOrFail($leadId)->update(['status' => $status]);
    }
}
