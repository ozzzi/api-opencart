<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

use App\Models\Bot\ChatSession;
use App\Services\Chat\DTO\ClarificationDecision;

interface ClarificationGateInterface
{
    /**
     * Decides whether search_products may answer this query or must ask first
     * (task-product-clarification.md §4).
     *
     * @param array<string, mixed> $arguments Raw search_products arguments.
     */
    public function evaluate(ChatSession $session, array $arguments): ClarificationDecision;
}
