<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\CreateLeadRequest;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\LeadServiceInterface;
use App\Services\Chat\LeadIdempotencyGuard;
use Illuminate\Http\JsonResponse;

final class ChatLeadController extends Controller
{
    public function __construct(
        private readonly LeadServiceInterface $leadService,
        private readonly LeadIdempotencyGuard $idempotencyGuard,
    ) {
    }

    public function __invoke(CreateLeadRequest $request): JsonResponse
    {
        /** @var ChatSession $session */
        $session = $request->attributes->get('chat_session');

        $idempotencyKey = $request->header('X-Idempotency-Key');

        if ($idempotencyKey !== null) {
            $cached = $this->idempotencyGuard->find($session->id, $idempotencyKey);

            if ($cached !== null) {
                return response()->json($cached['body'], $cached['status']);
            }
        }

        $lead = $this->leadService->create($session->id, $request->validated());

        $body = ['lead_id' => $lead->id];

        if ($idempotencyKey !== null) {
            $this->idempotencyGuard->remember($session->id, $idempotencyKey, 201, $body);
        }

        return response()->json($body, 201);
    }
}
