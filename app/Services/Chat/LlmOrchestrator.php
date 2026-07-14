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
use App\Settings\BotLlmSettings;
use Generator;

final class LlmOrchestrator
{
    private const int MAX_TOOL_ITERATIONS = 5;

    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly ConversationServiceInterface $conversationService,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly CostTrackerInterface $costTracker,
        private readonly ShopAssistantInterface $shopAssistant,
        private readonly RateLimiterInterface $rateLimiter,
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

        if (! $this->costTracker->checkBudget()) {
            throw new DailyBudgetExceededException;
        }

        $rateLimitResult = $this->rateLimiter->check($session->id, $session->ip_address ?? '');

        if (! $rateLimitResult->allowed) {
            throw new RateLimitExceededException(
                $rateLimitResult->limitType ?? 'global',
                $rateLimitResult->retryAfterSeconds,
            );
        }

        $detectedLang = $this->shopAssistant->detectLanguage($userMessage);
        $session->language = $detectedLang;

        $this->conversationService->addMessage($session, 'user', $userMessage);

        // 5 + 6. Build context window + prepend system prompt
        $messages = [
            new LlmChatMessage(
                role: 'system',
                content: $this->shopAssistant->buildSystemPrompt($session),
            ),
            ...$this->conversationService->buildContextWindow($session),
        ];

        // 7. Tool-call loop
        $startTime = hrtime(true);
        $finalResponse = $this->runToolLoop($session, $messages);
        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // Persist final assistant message
        $assistantMessage = $this->conversationService->addMessage(
            $session,
            'assistant',
            $finalResponse->content ?? '',
            [
                'model' => $this->llmClient->getModel(),
                'tokens_used' => $finalResponse->usage->promptTokens + $finalResponse->usage->completionTokens,
                'latency_ms' => $latencyMs,
            ],
        );

        // 8. Log cost
        $this->costTracker->log(
            sessionId: $session->id,
            messageId: $assistantMessage->id,
            response: $finalResponse,
            model: $this->llmClient->getModel(),
            type: 'chat',
            provider: $this->llmClient->getProvider(),
            latencyMs: $latencyMs,
        );

        // 10. Trigger summarization when the conversation grows long
        if ($this->conversationService->needsSummarization($session)) {
            SummarizeConversationJob::dispatch($session->id);
        }

        return $finalResponse;
    }

    // ── Streaming generator ───────────────────────────────────────────────────

    /**
     * Returns a Generator that performs all work lazily:
     *   - Checks budget/rate and throws on violation (caught by the SSE controller)
     *   - Yields Start, ToolRunning, ToolDone, Text, and Done chunks
     *
     * @return Generator<int, StreamChunk, null, void>
     *
     * @throws DailyBudgetExceededException
     * @throws RateLimitExceededException
     */
    private function streamProcess(ChatSession $session, string $userMessage): Generator
    {
        // 1. Budget guard
        if (! $this->costTracker->checkBudget()) {
            throw new DailyBudgetExceededException;
        }

        // 2. Rate limit guard
        $rateLimitResult = $this->rateLimiter->check($session->id, $session->ip_address ?? '');

        if (! $rateLimitResult->allowed) {
            throw new RateLimitExceededException(
                $rateLimitResult->limitType ?? 'global',
                $rateLimitResult->retryAfterSeconds,
            );
        }

        // 3. Detect language
        $session->language = $this->shopAssistant->detectLanguage($userMessage);

        // 4. Persist user message
        $this->conversationService->addMessage($session, 'user', $userMessage);

        // Signal stream start
        yield new StreamChunk(StreamChunkType::Start);

        // 5+6. Build context window + system prompt
        $messages = [
            new LlmChatMessage(
                role: 'system',
                content: $this->shopAssistant->buildSystemPrompt($session),
            ),
            ...$this->conversationService->buildContextWindow($session),
        ];

        // 7. Tool-call loop with inline tool event yields
        $startTime = hrtime(true);
        $finalResponse = null;

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            $request = new LlmRequest(
                messages: $messages,
                model: $this->llmClient->getModel(),
                maxTokens: $this->llmSettings->maxContextTokens,
                tools: $this->toolRegistry->getOpenAiTools(),
            );

            $response = $this->llmClient->complete($request);

            if ($response->finishReason !== 'tool_calls' || $response->toolCalls === []) {
                $finalResponse = $response;
                break;
            }

            $messages[] = new LlmChatMessage(
                role: 'assistant',
                content: null,
                toolCalls: $response->toolCalls,
            );

            $this->persistToolCallMessage($session, $response);

            foreach ($response->toolCalls as $toolCall) {
                yield new StreamChunk(StreamChunkType::ToolRunning, toolName: $toolCall->name);

                $result = $this->toolRegistry->execute(
                    $toolCall->name,
                    $toolCall->arguments,
                    $session,
                );

                $messages[] = new LlmChatMessage(
                    role: 'tool',
                    content: $result,
                    toolCallId: $toolCall->id,
                );

                $this->conversationService->addMessage($session, 'tool', $result, [
                    'tool_name' => $toolCall->name,
                ]);

                yield new StreamChunk(StreamChunkType::ToolDone, toolName: $toolCall->name);
            }
        }

        // Max iterations reached — do a final non-tool call
        if ($finalResponse === null) {
            $finalResponse = $this->llmClient->complete(new LlmRequest(
                messages: $messages,
                model: $this->llmClient->getModel(),
                maxTokens: $this->llmSettings->maxContextTokens,
            ));
        }

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // Persist final assistant message
        $assistantMessage = $this->conversationService->addMessage(
            $session,
            'assistant',
            $finalResponse->content ?? '',
            [
                'model' => $this->llmClient->getModel(),
                'tokens_used' => $finalResponse->usage->promptTokens + $finalResponse->usage->completionTokens,
                'latency_ms' => $latencyMs,
            ],
        );

        // 8. Log cost
        $this->costTracker->log(
            sessionId: $session->id,
            messageId: $assistantMessage->id,
            response: $finalResponse,
            model: $this->llmClient->getModel(),
            type: 'chat',
            provider: $this->llmClient->getProvider(),
            latencyMs: $latencyMs,
        );

        // 10. Trigger summarization if needed
        if ($this->conversationService->needsSummarization($session)) {
            SummarizeConversationJob::dispatch($session->id);
        }

        // 9. Text delta
        if ($finalResponse->content !== null && $finalResponse->content !== '') {
            yield new StreamChunk(StreamChunkType::Text, content: $finalResponse->content);
        }

        // Done with message_id for the feedback button
        yield new StreamChunk(StreamChunkType::Done, messageId: $assistantMessage->id);
    }

    // ── Tool-call loop ────────────────────────────────────────────────────────

    /**
     * Runs the synchronous tool-call loop until the model stops requesting tools
     * or MAX_TOOL_ITERATIONS is reached.
     *
     * @param array<LlmChatMessage> $messages
     */
    private function runToolLoop(ChatSession $session, array $messages): LlmResponse
    {
        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            $request = new LlmRequest(
                messages: $messages,
                model: $this->llmClient->getModel(),
                maxTokens: $this->llmSettings->maxContextTokens,
                tools: $this->toolRegistry->getOpenAiTools(),
            );

            $response = $this->llmClient->complete($request);

            if ($response->finishReason !== 'tool_calls' || $response->toolCalls === []) {
                return $response;
            }

            // Append assistant tool-call message (with no text content) to context
            $messages[] = new LlmChatMessage(
                role: 'assistant',
                content: null,
                toolCalls: $response->toolCalls,
            );

            // Persist the assistant tool-call turn
            $this->persistToolCallMessage($session, $response);

            // Execute each requested tool and append results
            foreach ($response->toolCalls as $toolCall) {
                $result = $this->toolRegistry->execute(
                    $toolCall->name,
                    $toolCall->arguments,
                    $session,
                );

                $messages[] = new LlmChatMessage(
                    role: 'tool',
                    content: $result,
                    toolCallId: $toolCall->id,
                );

                $this->conversationService->addMessage($session, 'tool', $result, [
                    'tool_name' => $toolCall->name,
                ]);
            }
        }

        // Max iterations reached without a stop response — return the last response
        // (content may be null; upstream caller will handle gracefully).
        return $this->llmClient->complete(new LlmRequest(
            messages: $messages,
            model: $this->llmClient->getModel(),
            maxTokens: $this->llmSettings->maxContextTokens,
        ));
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
