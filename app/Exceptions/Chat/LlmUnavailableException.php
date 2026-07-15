<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use RuntimeException;
use Throwable;

final class LlmUnavailableException extends RuntimeException
{
    /**
     * @param list<Throwable> $causes Exceptions collected from each attempted client.
     * @param list<array{model: string, provider: string, error: string, circuit_breaker_open: bool}> $attempts
     */
    public function __construct(
        private readonly array $causes = [],
        private readonly array $attempts = [],
    ) {
        parent::__construct(
            'All LLM clients failed or have open circuit breakers. '
            .'Attempts: '.count($causes).'.',
        );
    }

    /** @return list<Throwable> */
    public function getCauses(): array
    {
        return $this->causes;
    }

    /** @return list<array{model: string, provider: string, error: string, circuit_breaker_open: bool}> */
    public function getAttempts(): array
    {
        return $this->attempts;
    }
}
