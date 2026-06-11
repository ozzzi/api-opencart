<?php

declare(strict_types=1);

namespace App\Services\Chat\Llm;

use App\Services\Chat\Contracts\EmbeddingClientInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Embedding client backed by the local vector-api service (Python / SentenceTransformers).
 *
 * Request:  POST /vectorize  body {"text": ["str1", "str2", ...]}
 *            Authorization: Bearer <token>
 * Response: [[float, ...], [float, ...]]  — one L2-normalised vector per input text.
 */
final class LocalHttpEmbeddingClient implements EmbeddingClientInterface
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
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
        $response = Http::withToken($this->token)
            ->timeout(30)
            ->post($this->url, ['text' => $texts]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Local embedding service error: '.$response->body(),
                $response->status(),
            );
        }

        /** @var list<list<float>> $vectors */
        $vectors = $response->json();

        if (! is_array($vectors)) {
            throw new RuntimeException('Local embedding service returned unexpected response format.');
        }

        return $vectors;
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
