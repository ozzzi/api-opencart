<?php

declare(strict_types=1);

namespace Tests\Feature\Unit\Chat\Llm;

use App\Exceptions\Chat\CircuitBreakerOpenException;
use App\Exceptions\Chat\LlmUnavailableException;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\DTO\LlmChatMessage;
use App\Services\Chat\DTO\LlmRequest;
use App\Services\Chat\DTO\LlmResponse;
use App\Services\Chat\DTO\StreamChunk;
use App\Services\Chat\DTO\StreamChunkType;
use App\Services\Chat\DTO\UsageStats;
use App\Services\Chat\Llm\FallbackLlmClient;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class FallbackLlmClientTest extends TestCase
{
    // ── complete ──────────────────────────────────────────────────────────────

    public function test_complete_returns_primary_response_when_successful(): void
    {
        $response = $this->makeResponse('from primary');
        $primary = $this->mockClient('gpt-4o', 'openai');
        $primary->expects('complete')->andReturn($response);

        $reserve = $this->mockClient('gpt-3.5-turbo', 'openai');
        $reserve->allows('complete')->never();

        $result = $this->make([$primary, $reserve])->complete($this->makeRequest());

        $this->assertSame($response, $result);
    }

    public function test_complete_falls_through_to_reserve_when_primary_throws(): void
    {
        $reserveResponse = $this->makeResponse('from reserve');

        $primary = $this->mockClient('gpt-4o', 'openai');
        $primary->expects('complete')->andThrow(new RuntimeException('primary failed'));

        $reserve = $this->mockClient('gpt-3.5-turbo', 'openai');
        $reserve->expects('complete')->andReturn($reserveResponse);

        $result = $this->make([$primary, $reserve])->complete($this->makeRequest());

        $this->assertSame($reserveResponse, $result);
    }

    public function test_complete_falls_through_on_circuit_breaker_open(): void
    {
        $reserveResponse = $this->makeResponse('from reserve');

        $primary = $this->mockClient('gpt-4o', 'openai');
        $primary->expects('complete')
            ->andThrow(new CircuitBreakerOpenException('gpt-4o', 30));

        $reserve = $this->mockClient('gpt-3.5-turbo', 'openai');
        $reserve->expects('complete')->andReturn($reserveResponse);

        $result = $this->make([$primary, $reserve])->complete($this->makeRequest());

        $this->assertSame($reserveResponse, $result);
    }

    public function test_complete_throws_unavailable_when_all_clients_fail(): void
    {
        $primary = $this->mockClient('gpt-4o', 'openai');
        $primary->expects('complete')->andThrow(new RuntimeException('primary failed'));

        $reserve = $this->mockClient('gpt-3.5-turbo', 'openai');
        $reserve->expects('complete')->andThrow(new RuntimeException('reserve failed'));

        $this->expectException(LlmUnavailableException::class);

        $this->make([$primary, $reserve])->complete($this->makeRequest());
    }

    public function test_complete_unavailable_exception_carries_causes(): void
    {
        $e1 = new RuntimeException('primary failed');
        $e2 = new RuntimeException('reserve failed');

        $primary = $this->mockClient('gpt-4o', 'openai');
        $primary->expects('complete')->andThrow($e1);

        $reserve = $this->mockClient('gpt-3.5-turbo', 'openai');
        $reserve->expects('complete')->andThrow($e2);

        try {
            $this->make([$primary, $reserve])->complete($this->makeRequest());
            $this->fail('Expected LlmUnavailableException');
        } catch (LlmUnavailableException $e) {
            $this->assertSame([$e1, $e2], $e->getCauses());
        }
    }

    public function test_complete_works_with_single_client(): void
    {
        $response = $this->makeResponse('only client');
        $client = $this->mockClient('gpt-4o', 'openai');
        $client->expects('complete')->andReturn($response);

        $this->assertSame($response, $this->make([$client])->complete($this->makeRequest()));
    }

    // ── stream ────────────────────────────────────────────────────────────────

    public function test_stream_yields_from_primary_when_successful(): void
    {
        $primary = $this->mockClient('gpt-4o', 'openai');
        $primary->expects('stream')->andReturnUsing(function () {
            yield new StreamChunk(type: StreamChunkType::Text, content: 'Hello');
            yield new StreamChunk(type: StreamChunkType::Done);
        });

        $reserve = $this->mockClient('gpt-3.5-turbo', 'openai');
        $reserve->allows('stream')->never();

        $chunks = iterator_to_array($this->make([$primary, $reserve])->stream($this->makeRequest()));

        $this->assertCount(2, $chunks);
        $this->assertSame('Hello', $chunks[0]->content);
    }

    public function test_stream_falls_through_to_reserve_when_primary_throws(): void
    {
        $primary = $this->mockClient('gpt-4o', 'openai');
        $primary->expects('stream')->andReturnUsing(function () {
            throw new CircuitBreakerOpenException('gpt-4o', 30);
            yield; // make it a generator
        });

        $reserve = $this->mockClient('gpt-3.5-turbo', 'openai');
        $reserve->expects('stream')->andReturnUsing(function () {
            yield new StreamChunk(type: StreamChunkType::Text, content: 'reserve');
            yield new StreamChunk(type: StreamChunkType::Done);
        });

        $chunks = iterator_to_array($this->make([$primary, $reserve])->stream($this->makeRequest()));

        $this->assertCount(2, $chunks);
        $this->assertSame('reserve', $chunks[0]->content);
    }

    public function test_stream_throws_unavailable_when_all_clients_fail(): void
    {
        $primary = $this->mockClient('gpt-4o', 'openai');
        $primary->expects('stream')->andReturnUsing(function () {
            throw new RuntimeException('primary stream failed');
            yield;
        });

        $reserve = $this->mockClient('gpt-3.5-turbo', 'openai');
        $reserve->expects('stream')->andReturnUsing(function () {
            throw new RuntimeException('reserve stream failed');
            yield;
        });

        $this->expectException(LlmUnavailableException::class);

        iterator_to_array($this->make([$primary, $reserve])->stream($this->makeRequest()));
    }

    // ── delegation ────────────────────────────────────────────────────────────

    public function test_get_model_returns_primary_model(): void
    {
        $primary = $this->mockClient('gpt-4o', 'openai');
        $reserve = $this->mockClient('gpt-3.5-turbo', 'openai');

        $this->assertSame('gpt-4o', $this->make([$primary, $reserve])->getModel());
    }

    public function test_get_provider_returns_primary_provider(): void
    {
        $primary = $this->mockClient('gpt-4o', 'openai');

        $this->assertSame('openai', $this->make([$primary])->getProvider());
    }

    // ── constructor guard ─────────────────────────────────────────────────────

    public function test_throws_when_no_clients_given(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FallbackLlmClient([]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @param list<LlmClientInterface> $clients */
    private function make(array $clients): FallbackLlmClient
    {
        return new FallbackLlmClient($clients);
    }

    private function makeRequest(): LlmRequest
    {
        return new LlmRequest(
            messages: [new LlmChatMessage(role: 'user', content: 'Hi')],
            model: 'gpt-4o',
            maxTokens: 100,
        );
    }

    private function makeResponse(string $content): LlmResponse
    {
        return new LlmResponse(
            content: $content,
            toolCalls: [],
            finishReason: 'stop',
            usage: new UsageStats(promptTokens: 5, completionTokens: 3, costUsd: 0.0),
        );
    }

    private function mockClient(string $model, string $provider): MockInterface
    {
        $mock = Mockery::mock(LlmClientInterface::class);
        $mock->allows('getModel')->andReturn($model);
        $mock->allows('getProvider')->andReturn($provider);

        return $mock;
    }
}
