<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Exceptions\Chat\DailyBudgetExceededException;
use App\Exceptions\Chat\LlmUnavailableException;
use App\Exceptions\Chat\RateLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ChatMessageRequest;
use App\Models\Bot\ChatSession;
use App\Services\Chat\DTO\StreamChunkType;
use App\Services\Chat\LlmOrchestrator;
use App\Settings\BotChatSettings;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\StreamedEvent;
use Generator;

final class MessageController extends Controller
{
    /** Human-readable labels shown in the widget while a tool is running. */
    private const array TOOL_LABELS = [
        'search_knowledge_base' => 'Ищу информацию…',
        'search_products'       => 'Ищу товары…',
        'get_product_details'   => 'Уточняю детали…',
        'compare_products'      => 'Сравниваю товары…',
        'create_lead'           => 'Оформляю заявку…',
        'get_order_status'      => 'Проверяю заказ…',
    ];

    public function __construct(
        private readonly LlmOrchestrator $orchestrator,
        private readonly BotChatSettings $chatSettings,
    ) {
    }

    public function __invoke(ChatMessageRequest $request): StreamedResponse
    {
        /** @var ChatSession $session */
        $session = $request->attributes->get('chat_session');
        $message = (string) $request->validated()['message'];

        return response()->eventStream(function () use ($session, $message) {
            try {
                /** @var Generator $generator */
                $generator = $this->orchestrator->processMessage($session, $message, stream: true);

                foreach ($generator as $chunk) {
                    $event = match ($chunk->type) {
                        StreamChunkType::Start => new StreamedEvent(
                            event: 'start',
                            data: '{}',
                        ),
                        StreamChunkType::Text => new StreamedEvent(
                            event: 'delta',
                            data: json_encode(['text' => $chunk->content], JSON_UNESCAPED_UNICODE),
                        ),
                        StreamChunkType::ToolRunning => new StreamedEvent(
                            event: 'tool',
                            data: json_encode([
                                'name'   => $chunk->toolName,
                                'status' => 'running',
                                'label'  => self::TOOL_LABELS[$chunk->toolName ?? ''] ?? $chunk->toolName,
                            ], JSON_UNESCAPED_UNICODE),
                        ),
                        StreamChunkType::ToolDone => new StreamedEvent(
                            event: 'tool',
                            data: json_encode([
                                'name'   => $chunk->toolName,
                                'status' => 'done',
                            ], JSON_UNESCAPED_UNICODE),
                        ),
                        StreamChunkType::Done => new StreamedEvent(
                            event: 'done',
                            data: json_encode([
                                'message_id'    => $chunk->messageId,
                                'lead_suggested' => false,
                            ], JSON_UNESCAPED_UNICODE),
                        ),
                        default => null,
                    };

                    if ($event !== null) {
                        yield $event;
                    }
                }
            } catch (RateLimitExceededException $e) {
                yield new StreamedEvent(
                    event: 'limited',
                    data: json_encode([
                        'message' => 'Вы пишете слишком часто. Пожалуйста, подождите немного.',
                        'retry_after' => $e->retryAfterSeconds,
                    ], JSON_UNESCAPED_UNICODE),
                );
            } catch (DailyBudgetExceededException) {
                yield new StreamedEvent(
                    event: 'degraded',
                    data: json_encode([
                        'message'       => $this->chatSettings->degradedModeMessage,
                        'lead_suggested' => true,
                    ], JSON_UNESCAPED_UNICODE),
                );
            } catch (LlmUnavailableException) {
                yield new StreamedEvent(
                    event: 'degraded',
                    data: json_encode([
                        'message'       => $this->chatSettings->degradedModeMessage,
                        'lead_suggested' => true,
                    ], JSON_UNESCAPED_UNICODE),
                );
            }
        });
    }
}
