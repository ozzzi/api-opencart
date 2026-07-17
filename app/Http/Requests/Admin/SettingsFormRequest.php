<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class SettingsFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // bot_chat
            'systemPrompt'        => ['required', 'string', 'max:10000'],
            'greetingMessage'     => ['required', 'string', 'max:1000'],
            'consentText'         => ['required', 'string', 'max:2000'],
            'policyUrl'           => ['required', 'string', 'max:500'],
            'contextWindowSize'   => ['required', 'integer', 'min:5', 'max:50'],
            'summaryThreshold'    => ['required', 'integer', 'min:10', 'max:200'],
            'sessionTtlMinutes'   => ['required', 'integer', 'min:5', 'max:1440'],
            'degradedModeMessage' => ['required', 'string', 'max:500'],
            'summarizationPrompt' => ['required', 'string', 'max:5000'],

            // bot_llm
            'primaryModel'        => ['required', 'string', 'max:100'],
            'fallbackModel'       => ['required', 'string', 'max:100'],
            'embeddingProvider'   => ['required', 'in:local,openai'],
            'embeddingModel'      => ['required', 'string', 'max:100'],
            'embeddingDimensions' => ['required', 'integer', 'min:64', 'max:4096'],
            'maxContextTokens'    => ['required', 'integer', 'min:512', 'max:128000'],

            // bot_rate_limit
            'dailyBudgetUsd'        => ['required', 'numeric', 'min:0'],
            'budgetAlertThreshold'  => ['required', 'numeric', 'min:0.1', 'max:1.0'],
            'rateLimitSessionRpm'   => ['required', 'integer', 'min:1', 'max:1000'],
            'rateLimitIpRpm'        => ['required', 'integer', 'min:1', 'max:10000'],
            'rateLimitGlobalRpm'    => ['required', 'integer', 'min:1', 'max:100000'],

            // bot_notifications
            'leadEmailEnabled'      => ['nullable', 'boolean'],
            'leadEmailRecipient'    => ['nullable', 'email', 'max:255'],
            'leadTelegramEnabled'   => ['nullable', 'boolean'],
            'leadTelegramChatId'    => ['nullable', 'string', 'max:100'],
            'leadTelegramBotToken'  => ['nullable', 'string', 'max:255'],

            // bot_privacy
            'dataRetentionDays' => ['required', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'systemPrompt'        => 'системний промпт',
            'greetingMessage'     => 'вітальне повідомлення',
            'consentText'         => 'текст згоди',
            'policyUrl'           => 'посилання на політику конфіденційності',
            'contextWindowSize'   => 'розмір контекстного вікна',
            'summaryThreshold'    => 'поріг суммаризації',
            'sessionTtlMinutes'   => 'TTL сесії',
            'degradedModeMessage' => 'повідомлення деградованого режиму',
            'summarizationPrompt' => 'промпт суммаризації',
            'primaryModel'        => 'основна модель',
            'fallbackModel'       => 'резервна модель',
            'embeddingProvider'   => 'провайдер ембедингів',
            'embeddingModel'      => 'модель ембедингів',
            'embeddingDimensions' => 'розмірність вектора',
            'maxContextTokens'    => 'максимум токенів контексту',
            'dailyBudgetUsd'      => 'денний бюджет',
            'budgetAlertThreshold' => 'поріг сповіщення бюджету',
            'rateLimitSessionRpm'  => 'ліміт сесії',
            'rateLimitIpRpm'       => 'ліміт IP',
            'rateLimitGlobalRpm'   => 'глобальний ліміт',
            'leadEmailRecipient'   => 'email отримувача',
            'leadTelegramChatId'   => 'Telegram Chat ID',
            'leadTelegramBotToken' => 'Telegram Bot Token',
            'dataRetentionDays'    => 'термін зберігання даних',
        ];
    }
}
