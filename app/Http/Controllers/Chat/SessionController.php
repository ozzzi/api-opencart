<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Services\Chat\Contracts\ConversationServiceInterface;
use App\Settings\BotChatSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SessionController extends Controller
{
    public function __construct(
        private readonly ConversationServiceInterface $conversationService,
        private readonly BotChatSettings $chatSettings,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $session = $this->conversationService->createSession(
            ip: (string) $request->ip(),
            userAgent: (string) $request->userAgent(),
            lang: 'uk',
        );

        return response()->json([
            'session_id'   => $session->id,
            'greeting'     => $this->chatSettings->greetingMessage,
            'consent_text' => $this->chatSettings->consentText,
            'policy_url'   => $this->chatSettings->policyUrl,
        ], 201);
    }
}
