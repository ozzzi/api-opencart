<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'index' => ['required', 'string'],
            'query' => ['required', 'string'],
            'filters' => ['sometimes', 'array'],
            'filters.*.attribute' => ['required', 'string'],
            'filters.*.value' => ['required'],
            'filters.*.operator' => ['nullable', 'string'],
            'sorts' => ['sometimes', 'array'],
            'sorts.*.attribute' => ['required', 'string'],
            'sorts.*.direction' => ['nullable', 'string'],
            'offset' => ['nullable', 'numeric'],
            'limit' => ['nullable', 'numeric'],
            'exclude_fields' => ['sometimes', 'array'],
        ];
    }
}
