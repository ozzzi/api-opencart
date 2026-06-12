<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bot\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AdminLeadController extends Controller
{
    public function index(Request $request): View
    {
        $leads = Lead::query()
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(20);

        return view('admin.leads.index', compact('leads'));
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:new,contacted,closed,spam'],
        ]);

        $lead->update($validated);

        return back()->with('success', 'Статус заявки оновлено.');
    }
}
