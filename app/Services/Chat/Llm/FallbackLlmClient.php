<?php

declare(strict_types=1);

namespace App\Services\Chat\Llm;

use App\Exceptions\Chat\LlmUnavailableException;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\DTO\LlmRequest;
use App\Services\Chat\DTO\LlmResponse;
use Generator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Chain-of-responsibility: tries each client left-to-right (primary → reserve…).
 * Any exception (including CircuitBreakerOpenException) causes a fallthrough
 * to the next client. Throws LlmUnavailableException when all clients fail.
 *
 * The cascade starts as [OpenAI-primary, OpenAI-reserve-model].
 * Additional LlmClientInterface implementations (Ollama, Anthropic, …) can
 * be appended later without touching the orchestrator.
 */
final class FallbackLlmClient implements LlmClientInterface
{
    /** @var list<LlmClientInterface> */
    private readonly array $clients;

    /**
     * @param list<LlmClientInterface> $clients Ordered primary-first.
     */
    public function __construct(array $clients)
    {
        if ($clients === []) {
            throw new InvalidArgumentException('FallbackLlmClient requires at least one client.');
        }

        $this->clients = $clients;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $causes = [];

        foreach ($this->clients as $index => $client) {
            try {
                $response = $client->complete($request);

                if ($index > 0) {
                    Log::channel('chat')->warning('LLM fallback used', [
                        'fallback_index' => $index,
                        'model' => $client->getModel(),
                        'provider' => $client->getProvider(),
                    ]);
                }

                return $response;
            } catch (Throwable $e) {
                Log::channel('chat')->error('LLM client failed', [
                    'index' => $index,
                    'model' => $client->getModel(),
                    'error' => $e->getMessage(),
                ]);
                $causes[] = $e;
            }
        }

        throw new LlmUnavailableException($causes);
    }

    public function stream(LlmRequest $request): Generator
    {
        $causes = [];

        foreach ($this->clients as $index => $client) {
            try {
                yield from $client->stream($request);

                if ($index > 0) {
                    Log::channel('chat')->warning('LLM stream fallback used', [
                        'fallback_index' => $index,
                        'model' => $client->getModel(),
                        'provider' => $client->getProvider(),
                    ]);
                }

                return;
            } catch (Throwable $e) {
                Log::channel('chat')->error('LLM stream client failed', [
                    'index' => $index,
                    'model' => $client->getModel(),
                    'error' => $e->getMessage(),
                ]);
                $causes[] = $e;
            }
        }

        throw new LlmUnavailableException($causes);
    }

    public function getModel(): string
    {
        return $this->clients[0]->getModel();
    }

    public function getProvider(): string
    {
        return $this->clients[0]->getProvider();
    }
}
