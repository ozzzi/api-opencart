<?php

declare(strict_types=1);

namespace Tests\Feature\Unit\Chat\Llm;

use App\Exceptions\Chat\CircuitBreakerOpenException;
use App\Services\Chat\CircuitBreaker\CircuitBreakerInterface;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\DTO\LlmChatMessage;
use App\Services\Chat\DTO\LlmRequest;
use App\Services\Chat\DTO\LlmResponse;
use App\Services\Chat\DTO\StreamChunk;
use App\Services\Chat\DTO\StreamChunkType;
use App\Services\Chat\DTO\UsageStats;
use App\Services\Chat\Llm\LlmClientWithCircuitBreaker;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class LlmClientWithCircuitBreakerTest extends TestCase
{
    private const string MODEL = 'gpt-4o-mini';

    private MockInterface $inner;

    private MockInterface $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inner = Mockery::mock(LlmClientInterface::class);
        $this->inner->allows('getModel')->andReturn(self::MODEL);
        $this->inner->allows('getProvider')->andReturn('openai');

        $this->circuitBreaker = Mockery::mock(CircuitBreakerInterface::class);
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function test_complete_passes_through_when_circuit_closed(): void
    {
        $response = $this->makeResponse();

        $this->circuitBreaker->expects('isAvailable')->with(self::MODEL)->andReturn(true);
        $this->circuitBreaker->expects('recordSuccess')->with(self::MODEL);
        $this->inner->expects('complete')->andReturn($response);

        $result = $this->make()->complete($this->makeRequest());

        $this->assertSame($response, $result);
    }

    public function test_complete_throws_when_circuit_open(): void
    {
        $this->circuitBreaker->expects('isAvailable')->with(self::MODEL)->andReturn(false);
        $this->circuitBreaker->expects('retryAfterSeconds')->with(self::MODEL)->andReturn(30);
        $this->inner->allows('complete')->never();

        $this->expectException(CircuitBreakerOpenException::class);

        $this->make()->complete($this->makeRequest());
    }

    public function test_complete_records_failure_and_rethrows_on_error(): void
    {
        $error = new RuntimeException('API timeout');

        $this->circuitBreaker->expects('isAvailable')->with(self::MODEL)->andReturn(true);
        $this->circuitBreaker->expects('recordFailure')->with(self::MODEL);
        $this->inner->expects('complete')->andThrow($error);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API timeout');

        $this->make()->complete($this->makeRequest());
    }

    // ── stream ────────────────────────────────────────────────────────────────

    public function test_stream_passes_through_when_circuit_closed(): void
    {
        $chunks = [
            new StreamChunk(type: StreamChunkType::Text, content: 'Hello'),
            new StreamChunk(type: StreamChunkType::Done),
        ];

        $this->circuitBreaker->expects('isAvailable')->with(self::MODEL)->andReturn(true);
        $this->circuitBreaker->expects('recordSuccess')->with(self::MODEL);
        $this->inner->expects('stream')->andReturnUsing(function () use ($chunks) {
            yield from $chunks;
        });

        $result = iterator_to_array($this->make()->stream($this->makeRequest()));

        $this->assertCount(2, $result);
        $this->assertSame(StreamChunkType::Text, $result[0]->type);
    }

    public function test_stream_throws_when_circuit_open(): void
    {
        $this->circuitBreaker->expects('isAvailable')->with(self::MODEL)->andReturn(false);
        $this->circuitBreaker->expects('retryAfterSeconds')->with(self::MODEL)->andReturn(45);
        $this->inner->allows('stream')->never();

        $this->expectException(CircuitBreakerOpenException::class);

        iterator_to_array($this->make()->stream($this->makeRequest()));
    }

    public function test_stream_records_failure_and_rethrows_on_error(): void
    {
        $error = new RuntimeException('Stream error');

        $this->circuitBreaker->expects('isAvailable')->with(self::MODEL)->andReturn(true);
        $this->circuitBreaker->expects('recordFailure')->with(self::MODEL);
        $this->inner->expects('stream')->andReturnUsing(function () use ($error) {
            yield new StreamChunk(type: StreamChunkType::Text, content: 'Hi');
            throw $error;
        });

        $this->expectException(RuntimeException::class);

        iterator_to_array($this->make()->stream($this->makeRequest()));
    }

    // ── delegation ────────────────────────────────────────────────────────────

    public function test_get_model_delegates_to_inner(): void
    {
        $this->assertSame(self::MODEL, $this->make()->getModel());
    }

    public function test_get_provider_delegates_to_inner(): void
    {
        $this->assertSame('openai', $this->make()->getProvider());
    }

    private function make(): LlmClientWithCircuitBreaker
    {
        return new LlmClientWithCircuitBreaker($this->inner, $this->circuitBreaker);
    }

    private function makeRequest(): LlmRequest
    {
        return new LlmRequest(
            messages: [new LlmChatMessage(role: 'user', content: 'Hi')],
            model: self::MODEL,
            maxTokens: 100,
        );
    }

    private function makeResponse(): LlmResponse
    {
        return new LlmResponse(
            content: 'Hello!',
            toolCalls: [],
            finishReason: 'stop',
            usage: new UsageStats(promptTokens: 10, completionTokens: 5, costUsd: 0.0),
        );
    }
}
