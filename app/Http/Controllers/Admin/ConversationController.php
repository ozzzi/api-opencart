<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bot\ChatSession;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ConversationController extends Controller
{
    public function index(Request $request): View
    {
        $sessions = ChatSession::query()
            ->withCount('messages')
            ->when(
                $request->filled('language'),
                fn ($q) => $q->where('language', $request->input('language')),
            )
            ->when(
                $request->filled('date_from'),
                fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')),
            )
            ->when(
                $request->filled('date_to'),
                fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')),
            )
            ->latest('last_activity_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.conversations.index', compact('sessions'));
    }

    public function show(ChatSession $conversation): View
    {
        $conversation->load(['messages' => fn ($q) => $q->orderBy('created_at'), 'lead']);

        return view('admin.conversations.show', compact('conversation'));
    }
}
