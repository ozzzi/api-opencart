<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\CreateLeadRequest;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\LeadServiceInterface;
use Illuminate\Http\JsonResponse;

final class ChatLeadController extends Controller
{
    public function __construct(
        private readonly LeadServiceInterface $leadService,
    ) {
    }

    public function __invoke(CreateLeadRequest $request): JsonResponse
    {
        /** @var ChatSession $session */
        $session = $request->attributes->get('chat_session');

        $lead = $this->leadService->create($session->id, $request->validated());

        return response()->json(['lead_id' => $lead->id], 201);
    }
}
