<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

final class ChatFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message_id' => ['required', 'integer', 'min:1'],
            'rating'     => ['required', 'integer', 'in:1,-1'],
            'comment'    => ['nullable', 'string', 'max:1000'],
        ];
    }
}
