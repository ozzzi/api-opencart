<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Search;

use App\Services\Chat\Search\QueryTerms;
use Tests\TestCase;

final class QueryTermsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bot.clarification.stop_words', ['хочу', 'шукаю', 'мені']);
    }

    /**
     * The query that started all this: two significant terms, one of which only
     * ever appears in a product description.
     */
    public function test_keeps_the_discriminating_words_of_a_query(): void
    {
        $this->assertSame(['темляк', 'черепом'], QueryTerms::significant('темляк с черепом'));
    }

    public function test_drops_stop_words(): void
    {
        $this->assertSame(['браслет'], QueryTerms::significant('я хочу браслет'));
    }

    /**
     * Short tokens are prepositions and conjunctions in both languages — "з",
     * "с", "на" — and requiring them to match would sink every document.
     */
    public function test_drops_tokens_shorter_than_three_characters(): void
    {
        $this->assertSame(['браслет', 'кобра'], QueryTerms::significant('браслет з кобра'));
    }

    public function test_deduplicates_and_sorts_so_two_phrasings_compare_equal(): void
    {
        $this->assertSame(
            QueryTerms::significant('темляк паракорд'),
            QueryTerms::significant('Паракорд, ТЕМЛЯК паракорд!'),
        );
    }

    public function test_returns_nothing_for_a_query_made_only_of_noise(): void
    {
        $this->assertSame([], QueryTerms::significant('я хочу'));
    }
}
