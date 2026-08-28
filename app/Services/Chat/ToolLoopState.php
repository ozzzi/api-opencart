<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Services\Chat\DTO\LlmChatMessage;
use App\Services\Chat\DTO\LlmResponse;

/**
 * Mutable state carried through one turn's tool-call loop.
 *
 * Deliberately not readonly: the loop is a generator shared by the streaming and
 * synchronous paths, and a generator cannot hand a value back to two callers the way a
 * return does. Passing one object in and reading it out afterwards keeps both paths on
 * the same code and lets the repair pass (see LlmOrchestrator) re-enter the loop with
 * the conversation it left off with.
 */
final class ToolLoopState
{
    /** Round trip of the call that produced $finalResponse, kept for its cost record. */
    public int $finalResponseLatencyMs = 0;

    private int $promptTokens = 0;

    private int $completionTokens = 0;

    /**
     * @param list<LlmChatMessage> $messages              Conversation as the model sees it, appended to as tools run.
     * @param bool                 $searchReturnedResults Whether a search_products call in this turn answered "ok" with hits.
     * @param bool                 $emittedProductsBlock  Whether a products card block reached the customer this turn.
     */
    public function __construct(
        public array $messages,
        public bool $searchReturnedResults = false,
        public bool $emittedProductsBlock = false,
        public ?LlmResponse $finalResponse = null,
    ) {
    }

    /**
     * Books a completed model call against the turn.
     *
     * A turn is several calls — one per tool round, plus the answer, plus a repair pass
     * when one runs — and the message the customer ends up with was paid for by all of
     * them. Only the total belongs on the message.
     */
    public function recordUsage(LlmResponse $response): void
    {
        $this->promptTokens += $response->usage->promptTokens;
        $this->completionTokens += $response->usage->completionTokens;
    }

    /**
     * Marks the call that answered, keeping its own round trip.
     *
     * A repair pass overwrites this: the superseded answer is billed on its way out
     * (see LlmOrchestrator::appendRepairPrompt) and then stops being the reply.
     */
    public function answeredWith(LlmResponse $response, int $latencyMs): void
    {
        $this->finalResponse = $response;
        $this->finalResponseLatencyMs = $latencyMs;
    }

    /** Prompt plus completion tokens across every model call in the turn. */
    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    /**
     * True when the model found products but never showed a card for them.
     *
     * The invariant this guards is the whole point of splitting search from show
     * (task-structured-output.md §2.3): a successful search must end in cards built from
     * live data, not in prose the model composed from search hits.
     */
    public function productsFoundButNotShown(): bool
    {
        return $this->searchReturnedResults && ! $this->emittedProductsBlock;
    }
}
