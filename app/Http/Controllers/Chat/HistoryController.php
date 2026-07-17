<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Bot\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HistoryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var ChatSession $session */
        $session = $request->attributes->get('chat_session');

        $order = $request->query('order', 'asc');
        $order = in_array($order, ['asc', 'desc'], true) ? $order : 'asc';

        $messages = $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id', $order)
            ->paginate(20);

        return response()->json($messages);
    }
}
