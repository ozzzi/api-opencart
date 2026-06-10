<?php

declare(strict_types=1);

namespace App\Services\Chat\Llm;

use App\Exceptions\Chat\CircuitBreakerOpenException;
use App\Services\Chat\CircuitBreaker\CircuitBreakerInterface;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\DTO\LlmRequest;
use App\Services\Chat\DTO\LlmResponse;
use Generator;
use Throwable;

/**
 * Decorator that wraps any LlmClientInterface with circuit-breaker protection.
 *
 * Before each call: checks CircuitBreaker::isAvailable() — throws
 * CircuitBreakerOpenException if the circuit is open.
 * After each call: records success or failure and re-throws on failure.
 */
final class LlmClientWithCircuitBreaker implements LlmClientInterface
{
    public function __construct(
        private readonly LlmClientInterface $inner,
        private readonly CircuitBreakerInterface $circuitBreaker,
    ) {
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $model = $this->inner->getModel();

        $this->guardAvailable($model);

        try {
            $response = $this->inner->complete($request);
            $this->circuitBreaker->recordSuccess($model);

            return $response;
        } catch (Throwable $e) {
            $this->circuitBreaker->recordFailure($model);
            throw $e;
        }
    }

    public function stream(LlmRequest $request): Generator
    {
        $model = $this->inner->getModel();

        $this->guardAvailable($model);

        try {
            yield from $this->inner->stream($request);
            $this->circuitBreaker->recordSuccess($model);
        } catch (Throwable $e) {
            $this->circuitBreaker->recordFailure($model);
            throw $e;
        }
    }

    public function getModel(): string
    {
        return $this->inner->getModel();
    }

    public function getProvider(): string
    {
        return $this->inner->getProvider();
    }

    private function guardAvailable(string $model): void
    {
        if (! $this->circuitBreaker->isAvailable($model)) {
            throw new CircuitBreakerOpenException(
                $model,
                $this->circuitBreaker->retryAfterSeconds($model),
            );
        }
    }
}
