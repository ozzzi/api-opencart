<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Exceptions\Chat\DailyBudgetExceededException;
use App\Exceptions\Chat\RateLimitExceededException;
use App\Jobs\SummarizeConversationJob;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\ConversationServiceInterface;
use App\Services\Chat\Contracts\CostTrackerInterface;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\Contracts\RateLimiterInterface;
use App\Services\Chat\Contracts\ShopAssistantInterface;
use App\Services\Chat\Contracts\ToolRegistryInterface;
use App\Services\Chat\DTO\LlmChatMessage;
use App\Services\Chat\DTO\LlmRequest;
use App\Services\Chat\DTO\LlmResponse;
use App\Services\Chat\DTO\StreamChunk;
use App\Services\Chat\DTO\StreamChunkType;
use App\Services\Chat\DTO\ToolCall;
use App\Services\Chat\Presentation\BlockCollector;
use App\Services\Chat\Presentation\PartsAccumulator;
use App\Settings\BotLlmSettings;
use Generator;
use Illuminate\Support\Facades\Log;
use LogicException;

final class LlmOrchestrator
{
    private const int MAX_TOOL_ITERATIONS = 5;

    private const int MAX_REPAIR_ITERATIONS = 2;

    private const string SEARCH_PRODUCTS_TOOL = 'search_products';

    private const string PRODUCTS_BLOCK_TYPE = 'products';

    private const string PRODUCT_BLOCK_REPAIR = <<<'TEXT'
        Your last reply described products in prose. No product card has been shown to the customer, so they cannot see any price, image or link.
        Call show_products with the product_ids you recommend (1-4), then rewrite your reply as reasoning only — why each one suits this customer — with no prices, links or specifications.
        If none of the results actually fit, say so plainly and do not call show_products.
        TEXT;

    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly ConversationServiceInterface $conversationService,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly CostTrackerInterface $costTracker,
        private readonly ShopAssistantInterface $shopAssistant,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly BlockCollector $blockCollector,
        private readonly BotLlmSettings $llmSettings,
    ) {
    }

    /**
     * Process an incoming user message through the full tool-call loop.
     *
     * When $stream is false returns the final LlmResponse.
     * When $stream is true returns a Generator that yields StreamChunks
     * (text + done) so the caller can push SSE events incrementally.
     *
     * @throws DailyBudgetExceededException
     * @throws RateLimitExceededException
     *
     * @return Generator<int, StreamChunk, null, void>|LlmResponse
     */
    public function processMessage(
        ChatSession $session,
        string $userMessage,
        bool $stream = false,
    ): Generator|LlmResponse {
        if ($stream) {
            return $this->streamProcess($session, $userMessage);
        }

        $this->guardBudget();
        $this->guardRateLimit($session);

        $this->blockCollector->reset();
        $parts = new PartsAccumulator();

        $this->conversationService->addMessage($session, 'user', $userMessage);

        $state = new ToolLoopState($this->openingMessages($session));

        $startTime = hrtime(true);

        foreach ($this->run($session, $state, $parts) as $ignored) {
        }

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        $this->finalize($session, $state, $parts, $latencyMs);

        return $this->finalResponse($state);
    }

    // ── Streaming generator ───────────────────────────────────────────────────

    /**
     * Returns a Generator that performs all work lazily:
     *   - Checks budget/rate and throws on violation (caught by the SSE controller)
     *   - Yields Start, ToolRunning, ToolDone, Text, Block and Done chunks
     *
     * @return Generator<int, StreamChunk, null, void>
     *
     * @throws DailyBudgetExceededException
     * @throws RateLimitExceededException
     */
    private function streamProcess(ChatSession $session, string $userMessage): Generator
    {
        $this->guardBudget();
        $this->guardRateLimit($session);

        $this->blockCollector->reset();
        $parts = new PartsAccumulator();

        $userChatMessage = $this->conversationService->addMessage($session, 'user', $userMessage);

        // Signal stream start. The widget needs the user message's id up front so it
        // can reconcile the optimistic bubble it already rendered.
        yield new StreamChunk(StreamChunkType::Start, messageId: $userChatMessage->id);

        $state = new ToolLoopState($this->openingMessages($session));

        $startTime = hrtime(true);

        yield from $this->run($session, $state, $parts);

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        $assistantMessage = $this->finalize($session, $state, $parts, $latencyMs);

        $finalContent = $this->finalResponse($state)->content;

        if ($finalContent !== null && $finalContent !== '') {
            yield new StreamChunk(StreamChunkType::Text, content: $finalContent);
        }

        // Done with message_id for the feedback button
        yield new StreamChunk(StreamChunkType::Done, messageId: $assistantMessage->id);
    }

    // ── Tool-call loop ────────────────────────────────────────────────────────

    /**
     * The whole model-facing part of a turn: the tool loop, then a single repair pass
     * when products were found but never shown.
     *
     * @return Generator<int, StreamChunk, null, void>
     */
    private function run(ChatSession $session, ToolLoopState $state, PartsAccumulator $parts): Generator
    {
        yield from $this->iterate($session, $state, $parts, self::MAX_TOOL_ITERATIONS);

        if (! $state->productsFoundButNotShown()) {
            return;
        }

        $this->appendRepairPrompt($session, $state);

        yield from $this->iterate($session, $state, $parts, self::MAX_REPAIR_ITERATIONS);

        if ($state->productsFoundButNotShown()) {
            Log::channel('chat')->warning('Assistant found products but showed no card', [
                'session_id' => $session->id,
                'model' => $this->llmClient->getModel(),
            ]);
        }
    }

    /**
     * Runs up to $maxIterations model calls, executing every tool the model asks for and
     * leaving the answering response on $state.
     *
     * @return Generator<int, StreamChunk, null, void>
     */
    private function iterate(
        ChatSession $session,
        ToolLoopState $state,
        PartsAccumulator $parts,
        int $maxIterations,
    ): Generator {
        for ($i = 0; $i < $maxIterations; $i++) {
            // Keep the SSE connection alive across each blocking LLM call / tool
            // execution so a long tool-loop doesn't sit silent past a proxy's
            // read timeout.
            yield new StreamChunk(StreamChunkType::Heartbeat);

            $startedAt = hrtime(true);

            $response = $this->llmClient->complete(new LlmRequest(
                messages: $state->messages,
                model: $this->llmClient->getModel(),
                maxTokens: $this->llmSettings->maxContextTokens,
                tools: $this->toolRegistry->getOpenAiTools(),
            ));

            $callLatencyMs = $this->elapsedMs($startedAt);

            $state->recordUsage($response);

            if ($response->finishReason !== 'tool_calls' || $response->toolCalls === []) {
                $state->answeredWith($response, $callLatencyMs);

                // Logged by finalize(), once the assistant message it produced has an id
                // to point at — or by appendRepairPrompt() if a repair supersedes it.
                return;
            }

            // Prose the model wrote before reaching for a tool. OpenAI populates
            // `content` alongside tool_calls, and dropping it used to collapse every
            // reply into one trailing text part — the blocks would all land first.
            $interimContent = $response->content;

            if ($interimContent !== null && $interimContent !== '') {
                $parts->appendText($interimContent);

                yield new StreamChunk(StreamChunkType::Text, content: $interimContent);
            }

            $state->messages[] = new LlmChatMessage(
                role: 'assistant',
                content: $interimContent,
                toolCalls: $response->toolCalls,
            );

            $toolCallMessage = $this->persistToolCallMessage($session, $response);

            $this->logCall($session, $toolCallMessage->id, $response, $callLatencyMs);

            foreach ($response->toolCalls as $toolCall) {
                yield from $this->executeTool($session, $state, $parts, $toolCall);
            }
        }

        $startedAt = hrtime(true);

        $response = $this->llmClient->complete(new LlmRequest(
            messages: $state->messages,
            model: $this->llmClient->getModel(),
            maxTokens: $this->llmSettings->maxContextTokens,
        ));

        $state->recordUsage($response);
        $state->answeredWith($response, $this->elapsedMs($startedAt));
    }

    /**
     * @return Generator<int, StreamChunk, null, void>
     */
    private function executeTool(
        ChatSession $session,
        ToolLoopState $state,
        PartsAccumulator $parts,
        ToolCall $toolCall,
    ): Generator {
        yield new StreamChunk(StreamChunkType::ToolRunning, toolName: $toolCall->name);

        $result = $this->toolRegistry->execute($toolCall->name, $toolCall->arguments, $session);

        if ($toolCall->name === self::SEARCH_PRODUCTS_TOOL && $this->searchFoundProducts($result)) {
            $state->searchReturnedResults = true;
        }

        foreach ($this->blockCollector->drain() as $block) {
            if ($block->type() === self::PRODUCTS_BLOCK_TYPE) {
                $state->emittedProductsBlock = true;
            }

            $parts->pushBlock($block);

            yield new StreamChunk(StreamChunkType::Block, block: $block);
        }

        $state->messages[] = new LlmChatMessage(
            role: 'tool',
            content: $result,
            toolCallId: $toolCall->id,
        );

        $this->conversationService->addMessage($session, 'tool', $result, [
            'tool_name' => $toolCall->name,
            'tool_call_id' => $toolCall->id,
        ]);

        yield new StreamChunk(StreamChunkType::ToolDone, toolName: $toolCall->name);
    }

    private function searchFoundProducts(string $toolResult): bool
    {
        $decoded = json_decode($toolResult, true);

        if (! is_array($decoded) || ($decoded['status'] ?? null) !== 'ok') {
            return false;
        }

        $results = $decoded['results'] ?? null;

        return is_array($results) && $results !== [];
    }

    private function appendRepairPrompt(ChatSession $session, ToolLoopState $state): void
    {
        $superseded = $this->finalResponse($state);

        $this->logCall($session, null, $superseded, $state->finalResponseLatencyMs);

        $state->messages[] = new LlmChatMessage(
            role: 'assistant',
            content: $superseded->content ?? '',
        );

        $state->messages[] = new LlmChatMessage(
            role: 'system',
            content: self::PRODUCT_BLOCK_REPAIR,
        );
    }

    /**
     * System prompt plus the replayed conversation, as the model first sees the turn.
     *
     * @return list<LlmChatMessage>
     */
    private function openingMessages(ChatSession $session): array
    {
        return array_values([
            new LlmChatMessage(
                role: 'system',
                content: $this->shopAssistant->buildSystemPrompt($session),
            ),
            ...$this->conversationService->buildContextWindow($session),
        ]);
    }

    private function finalize(
        ChatSession $session,
        ToolLoopState $state,
        PartsAccumulator $parts,
        int $latencyMs,
    ): ChatMessage {
        $finalResponse = $this->finalResponse($state);

        if ($finalResponse->content !== null && $finalResponse->content !== '') {
            $parts->appendText($finalResponse->content);
        }

        $assistantParts = $parts->finish();

        $assistantMessage = $this->conversationService->addMessage(
            $session,
            'assistant',
            $parts->textContent(),
            [
                'model' => $this->llmClient->getModel(),
                'tokens_used' => $state->totalTokens(),
                'latency_ms' => $latencyMs,
                'parts' => $assistantParts,
            ],
        );

        $this->logCall($session, $assistantMessage->id, $finalResponse, $state->finalResponseLatencyMs);

        if ($this->conversationService->needsSummarization($session)) {
            SummarizeConversationJob::dispatch($session->id);
        }

        return $assistantMessage;
    }

    private function logCall(
        ChatSession $session,
        ?int $messageId,
        LlmResponse $response,
        int $latencyMs,
    ): void {
        $this->costTracker->log(
            sessionId: $session->id,
            messageId: $messageId,
            response: $response,
            model: $this->llmClient->getModel(),
            type: 'chat',
            provider: $this->llmClient->getProvider(),
            latencyMs: $latencyMs,
        );
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * Every exit from iterate() assigns a response, so a null here is a bug in the loop
     * rather than anything a caller can recover from.
     */
    private function finalResponse(ToolLoopState $state): LlmResponse
    {
        return $state->finalResponse
            ?? throw new LogicException('The tool loop finished without producing a response.');
    }

    /** @throws DailyBudgetExceededException */
    private function guardBudget(): void
    {
        if ($this->costTracker->checkBudget()) {
            return;
        }

        throw new DailyBudgetExceededException(
            currentSpendUsd: $this->costTracker->getDailyCost(),
            dailyBudgetUsd: $this->costTracker->getBudgetCapUsd(),
        );
    }

    /** @throws RateLimitExceededException */
    private function guardRateLimit(ChatSession $session): void
    {
        $result = $this->rateLimiter->check($session->id, $session->ip_address ?? '');

        if ($result->allowed) {
            return;
        }

        throw new RateLimitExceededException(
            $result->limitType ?? 'global',
            $result->retryAfterSeconds,
        );
    }

    private function persistToolCallMessage(ChatSession $session, LlmResponse $response): ChatMessage
    {
        return $this->conversationService->addMessage($session, 'assistant', '', [
            'tool_calls' => array_map(
                static fn (ToolCall $tc) => [
                    'id' => $tc->id,
                    'name' => $tc->name,
                    'arguments' => $tc->arguments,
                ],
                $response->toolCalls,
            ),
            'model' => $this->llmClient->getModel(),
        ]);
    }
}
