<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsFormRequest;
use App\Settings\BotChatSettings;
use App\Settings\BotLlmSettings;
use App\Settings\BotNotificationSettings;
use App\Settings\BotPrivacySettings;
use App\Settings\BotRateLimitSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class SettingsController extends Controller
{
    public function edit(
        BotChatSettings $chat,
        BotLlmSettings $llm,
        BotRateLimitSettings $rateLimit,
        BotNotificationSettings $notifications,
        BotPrivacySettings $privacy,
    ): View {
        return view('admin.settings.edit', compact(
            'chat',
            'llm',
            'rateLimit',
            'notifications',
            'privacy',
        ));
    }

    public function update(
        SettingsFormRequest $request,
        BotChatSettings $chat,
        BotLlmSettings $llm,
        BotRateLimitSettings $rateLimit,
        BotNotificationSettings $notifications,
        BotPrivacySettings $privacy,
    ): RedirectResponse {
        $data = $request->validated();

        $chat->systemPrompt = $data['systemPrompt'];
        $chat->greetingMessage = $data['greetingMessage'];
        $chat->consentText = $data['consentText'];
        $chat->policyUrl = $data['policyUrl'];
        $chat->contextWindowSize = (int) $data['contextWindowSize'];
        $chat->summaryThreshold = (int) $data['summaryThreshold'];
        $chat->sessionTtlMinutes = (int) $data['sessionTtlMinutes'];
        $chat->degradedModeMessage = $data['degradedModeMessage'];
        $chat->summarizationPrompt = $data['summarizationPrompt'];
        $chat->save();

        $llm->primaryModel = $data['primaryModel'];
        $llm->fallbackModel = $data['fallbackModel'];
        $llm->embeddingProvider = $data['embeddingProvider'];
        $llm->embeddingModel = $data['embeddingModel'];
        $llm->embeddingDimensions = (int) $data['embeddingDimensions'];
        $llm->maxContextTokens = (int) $data['maxContextTokens'];
        $llm->save();

        $rateLimit->dailyBudgetUsd = (float) $data['dailyBudgetUsd'];
        $rateLimit->budgetAlertThreshold = (float) $data['budgetAlertThreshold'];
        $rateLimit->rateLimitSessionRpm = (int) $data['rateLimitSessionRpm'];
        $rateLimit->rateLimitIpRpm = (int) $data['rateLimitIpRpm'];
        $rateLimit->rateLimitGlobalRpm = (int) $data['rateLimitGlobalRpm'];
        $rateLimit->save();

        $notifications->leadEmailEnabled = (bool) ($data['leadEmailEnabled'] ?? false);
        $notifications->leadEmailRecipient = $data['leadEmailRecipient'] ?? '';
        $notifications->leadTelegramEnabled = (bool) ($data['leadTelegramEnabled'] ?? false);
        $notifications->leadTelegramChatId = $data['leadTelegramChatId'] ?? '';
        if (filled($data['leadTelegramBotToken'] ?? null)) {
            $notifications->leadTelegramBotToken = $data['leadTelegramBotToken'];
        }
        $notifications->save();

        $privacy->dataRetentionDays = (int) $data['dataRetentionDays'];
        $privacy->save();

        return redirect()->route('admin.settings.edit')
            ->with('success', 'Налаштування збережено.');
    }
}
