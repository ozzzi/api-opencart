<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

use App\Models\Bot\Lead;

interface LeadServiceInterface
{
    /**
     * Create a new lead and dispatch notifications for all active channels.
     *
     * $data keys: name (string|null), phone (string|null), email (string|null),
     *             message (string|null), product_ids (list<int>|null).
     * At least one of phone / email must be present.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(string $sessionId, array $data): Lead;

    /**
     * Update the status of an existing lead.
     *
     * Valid statuses: new, contacted, closed, spam.
     */
    public function updateStatus(int $leadId, string $status): void;
}
