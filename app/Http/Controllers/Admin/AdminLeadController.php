<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bot\Lead;
use App\Models\OpenCart\OcProductDescription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

final class AdminLeadController extends Controller
{
    public function index(Request $request): View
    {
        $leads = Lead::query()
            ->with('session')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('name', 'like', "%{$v}%")
                    ->orWhere('phone', 'like', "%{$v}%")
                    ->orWhere('email', 'like', "%{$v}%");
            }))
            ->when($request->input('date_from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->input('date_to'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $productNames = $this->loadProductNames($leads->getCollection());

        return view('admin.leads.index', compact('leads', 'productNames'));
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:new,contacted,closed,spam'],
        ]);

        $lead->update($validated);

        return back()->with('success', 'Статус заявки оновлено.');
    }

    /** @param Collection<int, Lead> $leads */
    private function loadProductNames(Collection $leads): array
    {
        $productIds = $leads
            ->pluck('product_ids')
            ->filter()
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($productIds)) {
            return [];
        }

        $languageId = (int) (config('opencart.language_map.uk') ?? config('opencart.language_map.ru') ?? 1);

        return OcProductDescription::query()
            ->whereIn('product_id', $productIds)
            ->where('language_id', $languageId)
            ->get(['product_id', 'name'])
            ->pluck('name', 'product_id')
            ->all();
    }
}
