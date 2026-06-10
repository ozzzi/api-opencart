<?php

declare(strict_types=1);

namespace App\Services\Chat\Llm;

use App\Exceptions\Chat\LlmUnavailableException;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\DTO\LlmRequest;
use App\Services\Chat\DTO\LlmResponse;
use Generator;
use Throwable;
use InvalidArgumentException;

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

        foreach ($this->clients as $client) {
            try {
                return $client->complete($request);
            } catch (Throwable $e) {
                $causes[] = $e;
            }
        }

        throw new LlmUnavailableException($causes);
    }

    public function stream(LlmRequest $request): Generator
    {
        $causes = [];

        foreach ($this->clients as $client) {
            try {
                yield from $client->stream($request);

                return;
            } catch (Throwable $e) {
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
