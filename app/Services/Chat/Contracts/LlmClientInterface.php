<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

use App\Services\Chat\DTO\LlmRequest;
use App\Services\Chat\DTO\LlmResponse;
use App\Services\Chat\DTO\StreamChunk;
use Generator;

interface LlmClientInterface
{
    public function complete(LlmRequest $request): LlmResponse;

    /**
     * @return Generator<StreamChunk>
     */
    public function stream(LlmRequest $request): Generator;

    public function getModel(): string;

    public function getProvider(): string;
}
