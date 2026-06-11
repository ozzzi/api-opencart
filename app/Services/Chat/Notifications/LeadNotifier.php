<?php

declare(strict_types=1);

namespace App\Services\Chat\Notifications;

use App\Models\Bot\Lead;
use App\Services\Chat\Contracts\LeadNotifierInterface;
use App\Services\Chat\Contracts\NotificationChannelInterface;

final class LeadNotifier implements LeadNotifierInterface
{
    /** @param NotificationChannelInterface[] $channels */
    public function __construct(
        private readonly array $channels,
    ) {
    }

    public function notify(Lead $lead): void
    {
        $subject = $this->buildSubject($lead);
        $body = $this->buildBody($lead);

        foreach ($this->channels as $channel) {
            $channel->send($subject, $body);
        }
    }

    private function buildSubject(Lead $lead): string
    {
        return $lead->name !== null && $lead->name !== ''
            ? "New lead #{$lead->id}: {$lead->name}"
            : "New lead #{$lead->id}";
    }

    private function buildBody(Lead $lead): string
    {
        $lines = [];

        if ($lead->name !== null && $lead->name !== '') {
            $lines[] = "Name: {$lead->name}";
        }

        if ($lead->phone !== null && $lead->phone !== '') {
            $lines[] = "Phone: {$lead->phone}";
        }

        if ($lead->email !== null && $lead->email !== '') {
            $lines[] = "Email: {$lead->email}";
        }

        if ($lead->message !== null && $lead->message !== '') {
            $lines[] = '';
            $lines[] = "Message: {$lead->message}";
        }

        if (! empty($lead->product_ids)) {
            $lines[] = 'Products: '.implode(', ', $lead->product_ids);
        }

        return implode("\n", $lines);
    }
}
