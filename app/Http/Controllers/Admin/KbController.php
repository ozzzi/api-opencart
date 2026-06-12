<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bot\KnowledgeBaseArticle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class KbController extends Controller
{
    public function index(): View
    {
        $articles = KnowledgeBaseArticle::query()->latest()->paginate(20);

        return view('admin.kb.index', compact('articles'));
    }

    public function create(): View
    {
        return view('admin.kb.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title'        => ['required', 'string', 'max:500'],
            'content'      => ['required', 'string'],
            'category'     => ['nullable', 'string', 'max:100'],
            'lang'         => ['required', 'in:uk,ru'],
            'is_published' => ['boolean'],
        ]);

        KnowledgeBaseArticle::create($validated);

        return redirect()->route('admin.kb.index')->with('success', 'Статтю збережено.');
    }

    public function edit(KnowledgeBaseArticle $kb): View
    {
        return view('admin.kb.edit', compact('kb'));
    }

    public function update(Request $request, KnowledgeBaseArticle $kb): RedirectResponse
    {
        $validated = $request->validate([
            'title'        => ['required', 'string', 'max:500'],
            'content'      => ['required', 'string'],
            'category'     => ['nullable', 'string', 'max:100'],
            'lang'         => ['required', 'in:uk,ru'],
            'is_published' => ['boolean'],
        ]);

        $kb->update($validated);

        return redirect()->route('admin.kb.index')->with('success', 'Статтю оновлено.');
    }

    public function destroy(KnowledgeBaseArticle $kb): RedirectResponse
    {
        $kb->delete();

        return redirect()->route('admin.kb.index')->with('success', 'Статтю видалено.');
    }
}
