<?php

declare(strict_types=1);

namespace App\Services\Chat\Llm;

use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\Cost\CostCalculator;
use App\Services\Chat\DTO\LlmChatMessage;
use App\Services\Chat\DTO\LlmRequest;
use App\Services\Chat\DTO\LlmResponse;
use App\Services\Chat\DTO\StreamChunk;
use App\Services\Chat\DTO\StreamChunkType;
use App\Services\Chat\DTO\ToolCall;
use App\Services\Chat\DTO\UsageStats;
use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiChatClient implements LlmClientInterface
{
    private const string BASE_URL = 'https://api.openai.com/v1';

    private const string PROVIDER = 'openai';

    public function __construct(
        private readonly CostCalculator $costCalculator,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post(self::BASE_URL.'/chat/completions', $this->buildPayload($request, stream: false));

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenAI API error: '.$response->body(),
                $response->status(),
            );
        }

        $data = $response->json();
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $usage = $data['usage'] ?? [];

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = new ToolCall(
                id: $tc['id'],
                name: $tc['function']['name'],
                arguments: json_decode($tc['function']['arguments'], true) ?? [],
            );
        }

        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);

        return new LlmResponse(
            content: $message['content'] ?? null,
            toolCalls: $toolCalls,
            finishReason: $choice['finish_reason'] ?? 'stop',
            usage: new UsageStats(
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                costUsd: $this->costCalculator->calculate($request->model, $promptTokens, $completionTokens),
            ),
        );
    }

    public function stream(LlmRequest $request): Generator
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(120)
            ->withOptions(['stream' => true])
            ->post(self::BASE_URL.'/chat/completions', $this->buildPayload($request, stream: true));

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenAI API error: '.$response->body(),
                $response->status(),
            );
        }

        yield from $this->parseStream($response->toPsrResponse()->getBody(), $request->model);
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getProvider(): string
    {
        return self::PROVIDER;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(LlmRequest $request, bool $stream): array
    {
        $payload = [
            'model' => $request->model,
            'messages' => array_map($this->serializeMessage(...), $request->messages),
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'stream' => $stream,
        ];

        if ($stream) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        if ($request->tools !== null) {
            $payload['tools'] = $request->tools;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(LlmChatMessage $message): array
    {
        $data = ['role' => $message->role];

        if ($message->content !== null) {
            $data['content'] = $message->content;
        }

        if ($message->toolCalls !== null) {
            $data['tool_calls'] = array_map(static fn (ToolCall $tc) => [
                'id' => $tc->id,
                'type' => 'function',
                'function' => [
                    'name' => $tc->name,
                    'arguments' => json_encode($tc->arguments),
                ],
            ], $message->toolCalls);
        }

        if ($message->toolCallId !== null) {
            $data['tool_call_id'] = $message->toolCallId;
        }

        return $data;
    }

    /**
     * Parses an OpenAI SSE stream, yielding StreamChunk instances.
     * Tool calls are accumulated by index and emitted on [DONE].
     *
     * @param \Psr\Http\Message\StreamInterface $body
     * @return Generator<StreamChunk>
     */
    private function parseStream(mixed $body, string $model): Generator
    {
        /** @var array<int, array{id: string, name: string, arguments: string}> $pendingToolCalls */
        $pendingToolCalls = [];
        $promptTokens = 0;
        $completionTokens = 0;
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($newlinePos = mb_strpos($buffer, "\n")) !== false) {
                $line = mb_rtrim(mb_substr($buffer, 0, $newlinePos), "\r");
                $buffer = mb_substr($buffer, $newlinePos + 1);

                if ($line === '' || ! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = mb_substr($line, 6);

                if ($data === '[DONE]') {
                    foreach ($pendingToolCalls as $tc) {
                        yield new StreamChunk(
                            type: StreamChunkType::ToolCallDelta,
                            toolCall: new ToolCall(
                                id: $tc['id'],
                                name: $tc['name'],
                                arguments: json_decode($tc['arguments'], true) ?? [],
                            ),
                        );
                    }

                    yield new StreamChunk(
                        type: StreamChunkType::Done,
                        content: json_encode([
                            'prompt_tokens' => $promptTokens,
                            'completion_tokens' => $completionTokens,
                            'cost_usd' => $this->costCalculator->calculate($model, $promptTokens, $completionTokens),
                        ]),
                    );

                    return;
                }

                $chunk = json_decode($data, true);
                if (! is_array($chunk)) {
                    continue;
                }

                // Capture final usage stats sent via stream_options.include_usage
                if (isset($chunk['usage'])) {
                    $promptTokens = (int) ($chunk['usage']['prompt_tokens'] ?? $promptTokens);
                    $completionTokens = (int) ($chunk['usage']['completion_tokens'] ?? $completionTokens);
                }

                $choice = $chunk['choices'][0] ?? null;
                if (! is_array($choice)) {
                    continue;
                }

                $delta = $choice['delta'] ?? [];

                if (isset($delta['content']) && $delta['content'] !== null && $delta['content'] !== '') {
                    yield new StreamChunk(type: StreamChunkType::Text, content: $delta['content']);
                }

                // Accumulate tool call fragments by index; arguments arrive as JSON string chunks
                foreach ($delta['tool_calls'] ?? [] as $tcDelta) {
                    $index = (int) ($tcDelta['index'] ?? 0);

                    if (! isset($pendingToolCalls[$index])) {
                        $pendingToolCalls[$index] = ['id' => '', 'name' => '', 'arguments' => ''];
                    }

                    $pendingToolCalls[$index]['id'] .= (string) ($tcDelta['id'] ?? '');
                    $pendingToolCalls[$index]['name'] .= (string) ($tcDelta['function']['name'] ?? '');
                    $pendingToolCalls[$index]['arguments'] .= (string) ($tcDelta['function']['arguments'] ?? '');
                }
            }
        }
    }
}
