<?php

declare(strict_types=1);

namespace App\Services\Chat\Search;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\ClarificationGateInterface;
use App\Services\Chat\Contracts\ConversationServiceInterface;
use App\Services\Chat\DTO\ClarificationDecision;
use App\Settings\BotChatSettings;

/**
 * Decides whether search_products may answer a query or must ask first
 *
 * The decision lives here rather than in the system prompt on purpose:
 * the prompt is a probabilistic layer and the model is systematically biased
 * towards calling the tool and showing whatever comes back.  A server-side gate
 * is deterministic and testable; the prompt then only has to describe the
 * protocol instead of talking the model out of its default behaviour.
 */
final class ClarificationGate implements ClarificationGateInterface
{
    public function __construct(
        private readonly CatalogBreadthProbe $probe,
        private readonly ConversationServiceInterface $conversation,
        private readonly BotChatSettings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments Raw search_products arguments.
     */
    public function evaluate(ChatSession $session, array $arguments): ClarificationDecision
    {
        if (! $this->enabled()) {
            return ClarificationDecision::proceed();
        }

        // A stated budget is itself a discriminating feature — nothing to ask about.
        if (isset($arguments['price_min']) || isset($arguments['price_max'])) {
            return ClarificationDecision::proceed();
        }

        $terms = $this->significantTerms((string) ($arguments['query'] ?? ''));

        if ($terms === [] || count($terms) > $this->maxQueryTerms()) {
            return ClarificationDecision::proceed();
        }

        $state = $this->conversation->getClarificationState($session);

        if ($this->hasOptedOut($session, $arguments, $state['opted_out'])) {
            $this->conversation->updateClarificationState($session, ['opted_out' => true]);

            return ClarificationDecision::proceed();
        }

        // A new topic starts its own budget of questions: the counter belongs to
        // the picking task, not to the session.
        $rounds = $state['last_query_terms'] === $terms ? $state['rounds'] : 0;

        if ($rounds >= $this->maxRounds()) {
            $this->conversation->updateClarificationState($session, [
                'rounds'           => $rounds,
                'last_query_terms' => $terms,
            ]);

            return ClarificationDecision::diversify();
        }

        $breadth = $this->probe->run((string) $arguments['query']);

        if ($breadth->totalHits <= $this->broadHitsThreshold()) {
            $this->conversation->updateClarificationState($session, [
                'rounds'           => $rounds,
                'last_query_terms' => $terms,
            ]);

            return ClarificationDecision::proceed();
        }

        $rounds++;

        $this->conversation->updateClarificationState($session, [
            'rounds'           => $rounds,
            'last_query_terms' => $terms,
        ]);

        return ClarificationDecision::ask($breadth, $rounds);
    }

    // -------------------------------------------------------------------------

    /**
     * Words that actually narrow the search. Everything shorter than three
     * characters or listed as a stop word is dropped, so "я хочу браслет"
     * counts as one term while "браслет кобра з фастексом" counts as three.
     *
     * No stemming on purpose: a missed match only means the gate stays shut and
     * behaviour falls back to what it was before the gate existed.
     *
     * @return list<string> Sorted and de-duplicated, so it can be compared
     *                      against the stored terms of the previous query.
     */
    public function significantTerms(string $query): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), flags: PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        /** @var list<string> $stopWords */
        $stopWords = (array) config('bot.clarification.stop_words', []);

        $terms = array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token) >= 3
                && ! in_array($token, $stopWords, strict: true),
        );

        $terms = array_values(array_unique($terms));
        sort($terms);

        return $terms;
    }

    /**
     * Two layers, as with prompt injection (§14 architect): the model is asked to
     * pass skip_clarification, and the server independently reads the customer's
     * own words. The second layer is what holds when the model ignores the first.
     *
     * @param array<string, mixed> $arguments
     */
    private function hasOptedOut(ChatSession $session, array $arguments, bool $alreadyOptedOut): bool
    {
        if ($alreadyOptedOut) {
            return true;
        }

        if (($arguments['skip_clarification'] ?? null) === true) {
            return true;
        }

        $message = $this->latestUserMessage($session);

        if ($message === '') {
            return false;
        }

        /** @var list<string> $phrases */
        $phrases = (array) config('bot.clarification.opt_out_phrases', []);

        foreach ($phrases as $phrase) {
            if (str_contains($message, mb_strtolower($phrase))) {
                return true;
            }
        }

        return false;
    }

    private function latestUserMessage(ChatSession $session): string
    {
        $message = $session->messages()
            ->where('role', 'user')
            ->orderByDesc('id')
            ->value('content');

        return mb_strtolower((string) $message);
    }

    private function enabled(): bool
    {
        return $this->settings->clarificationEnabled
            && (bool) config('bot.clarification.enabled', true);
    }

    private function broadHitsThreshold(): int
    {
        return $this->settings->clarificationBroadHitsThreshold;
    }

    private function maxQueryTerms(): int
    {
        return (int) config('bot.clarification.max_query_terms', 2);
    }

    private function maxRounds(): int
    {
        return (int) config('bot.clarification.max_rounds', 1);
    }
}
