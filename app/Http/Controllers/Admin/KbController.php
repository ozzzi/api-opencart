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
    public function index(Request $request): View
    {
        $articles = KnowledgeBaseArticle::query()
            ->when($request->input('lang'), fn ($q, $v) => $q->where('lang', $v))
            ->when($request->input('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->input('status') === 'published', fn ($q) => $q->where('is_published', true))
            ->when($request->input('status') === 'draft', fn ($q) => $q->where('is_published', false))
            ->when($request->input('search'), fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('title', 'like', "%{$v}%")->orWhere('content', 'like', "%{$v}%");
            }))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $categories = KnowledgeBaseArticle::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view('admin.kb.index', compact('articles', 'categories'));
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
