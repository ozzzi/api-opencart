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
            ->latest('last_activity_at')
            ->paginate(20);

        return view('admin.conversations.index', compact('sessions'));
    }

    public function show(ChatSession $conversation): View
    {
        $conversation->load('messages');

        return view('admin.conversations.show', compact('conversation'));
    }
}
