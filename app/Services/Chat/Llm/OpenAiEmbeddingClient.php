<?php

declare(strict_types=1);

namespace App\Services\Chat\Llm;

use App\Services\Chat\Contracts\CostTrackerInterface;
use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Cost\CostCalculator;
use App\Services\Chat\DTO\LlmResponse;
use App\Services\Chat\DTO\UsageStats;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Embedding client backed by the OpenAI Embeddings API.
 *
 * Each successful call is logged to llm_api_calls via CostTracker (type = embedding).
 * When called outside a chat session (e.g. background indexing), sessionId is null.
 */
final class OpenAiEmbeddingClient implements EmbeddingClientInterface
{
    private const string PROVIDER = 'openai';

    public function __construct(
        private readonly CostTrackerInterface $costTracker,
        private readonly CostCalculator $costCalculator,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $dimensions,
    ) {
    }

    /**
     * @param  list<string>      $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        $startedAt = hrtime(true);

        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post($this->baseUrl.'/embeddings', [
                'model' => $this->model,
                'input' => $texts,
            ]);

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenAI Embeddings API error: '.$response->body(),
                $response->status(),
            );
        }

        $data = $response->json();

        $promptTokens = (int) ($data['usage']['total_tokens'] ?? 0);
        $costUsd = $this->costCalculator->calculateEmbedding($this->model, $promptTokens);

        $this->costTracker->log(
            sessionId: null,
            messageId: null,
            response: new LlmResponse(
                content: null,
                toolCalls: [],
                finishReason: 'embedding',
                usage: new UsageStats(
                    promptTokens: $promptTokens,
                    completionTokens: 0,
                    costUsd: $costUsd,
                ),
            ),
            model: $this->model,
            type: 'embedding',
            provider: self::PROVIDER,
            latencyMs: $latencyMs,
        );

        /** @var list<array{index:int,embedding:list<float>}> $items */
        $items = $data['data'] ?? [];

        usort($items, fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_column($items, 'embedding');
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }
}
