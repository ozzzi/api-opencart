<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

use App\Models\Bot\Lead;

interface LeadNotifierInterface
{
    public function notify(Lead $lead): void;
}
