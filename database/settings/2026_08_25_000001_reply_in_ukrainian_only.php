<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Makes the assistant reply in Ukrainian only, whatever language the visitor writes in.
 *
 * The original prompt said "Always respond in the language of the user", which is the
 * opposite rule — it is dropped where it is still present. The language block is then
 * appended behind a marker so re-running migrations cannot duplicate it and an
 * operator's own edits to the rest of the prompt survive.
 *
 * The greeting, consent and degraded-mode texts shipped in English. They are only
 * replaced while they still hold that exact default: anything else is an operator's
 * wording, and overwriting it would silently discard their work.
 */
return new class extends SettingsMigration {
    private const string MARKER = '## Language';

    private const string RULES = <<<'TEXT'
        1. Always reply in Ukrainian, no matter which language the customer writes in. Russian, surzhyk and misspelled input must still be answered in Ukrainian.
        2. Do not apologise for the language, do not comment on it, and never repeat the answer in a second language.
        3. If the customer asks you to switch to another language, politely continue in Ukrainian.
        4. Knowledge base fragments may be written in Russian or Ukrainian. Use them as sources whatever their language, and phrase your answer in Ukrainian.
        TEXT;

    private const string OLD_LANGUAGE_RULE = 'Always respond in the language of the user.';

    private const string DEFAULT_GREETING = 'Hello! How can I help you today?';

    private const string DEFAULT_CONSENT = 'By using this chat, you agree to our privacy policy.';

    private const string DEFAULT_DEGRADED = 'The assistant is temporarily unavailable. Please leave a request and we will contact you.';

    public function up(): void
    {
        $this->migrator->inGroup('bot_chat', function (SettingsBlueprint $blueprint): void {
            $blueprint->update('systemPrompt', static function (string $prompt): string {
                $prompt = mb_trim(str_replace(self::OLD_LANGUAGE_RULE, '', $prompt));

                if (str_contains($prompt, self::MARKER)) {
                    return $prompt;
                }

                return $prompt."\n\n".self::MARKER."\n".self::RULES;
            });

            $blueprint->update('greetingMessage', static fn (string $value): string => $value === self::DEFAULT_GREETING
                ? 'Вітаю! Чим можу допомогти?'
                : $value);

            $blueprint->update('consentText', static fn (string $value): string => $value === self::DEFAULT_CONSENT
                ? 'Користуючись чатом, ви погоджуєтеся з нашою політикою конфіденційності.'
                : $value);

            $blueprint->update('degradedModeMessage', static fn (string $value): string => $value === self::DEFAULT_DEGRADED
                ? 'Вибачте, помічник тимчасово недоступний. Залиште заявку — і менеджер зв\'яжеться з вами.'
                : $value);
        });
    }
};
