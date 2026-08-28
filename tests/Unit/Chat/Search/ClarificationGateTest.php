<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Search;

use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\AlertNotifierInterface;
use App\Services\Chat\ConversationService;
use App\Services\Chat\Search\CatalogBreadthProbe;
use App\Services\Chat\Search\ClarificationGate;
use App\Settings\BotChatSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use OpenSearch\Client;
use ReflectionClass;
use Tests\TestCase;
use RuntimeException;

final class ClarificationGateTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $client;

    private BotChatSettings $settings;

    private ClarificationGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bot.clarification.enabled', true);
        config()->set('bot.clarification.max_query_terms', 2);
        config()->set('bot.clarification.max_rounds', 1);
        config()->set('bot.clarification.stop_words', ['хочу', 'мені', 'потрібно', 'нужен', 'мне']);
        config()->set('bot.clarification.opt_out_phrases', ['покажи що є', 'неважливо']);

        $this->client = Mockery::mock(Client::class);

        $this->settings = (new ReflectionClass(BotChatSettings::class))->newInstanceWithoutConstructor();
        $this->settings->sessionTtlMinutes = 60;
        $this->settings->contextWindowSize = 5;
        $this->settings->summaryThreshold = 10;
        $this->settings->clarificationEnabled = true;
        $this->settings->clarificationBroadHitsThreshold = 12;

        /** @var Client $client */
        $client = $this->client;

        $notifier = Mockery::mock(AlertNotifierInterface::class);
        $notifier->allows('isEnabled')->andReturnFalse();

        /** @var AlertNotifierInterface $notifier */
        $this->gate = new ClarificationGate(
            new CatalogBreadthProbe($client),
            new ConversationService($this->settings, $notifier),
            $this->settings,
        );
    }

    // ── significant terms ─────────────────────────────────────────────────────

    public function test_stop_words_and_short_tokens_are_not_significant(): void
    {
        $this->assertSame(['браслет'], $this->gate->significantTerms('я хочу браслет'));
    }

    public function test_significant_terms_are_sorted_and_deduplicated(): void
    {
        $this->assertSame(
            ['браслет', 'кобра'],
            $this->gate->significantTerms('Кобра браслет кобра!'),
        );
    }

    // ── gate fires ────────────────────────────────────────────────────────────

    public function test_broad_query_is_asked_about(): void
    {
        $this->probeReturns(totalHits: 74);

        $session = ChatSession::factory()->create();

        $decision = $this->gate->evaluate($session, ['query' => 'браслет']);

        $this->assertTrue($decision->needsClarification);
        $this->assertSame(1, $decision->round);
        $this->assertSame(74, $decision->breadth?->totalHits);
    }

    public function test_round_counter_is_persisted(): void
    {
        $this->probeReturns(totalHits: 74);

        $session = ChatSession::factory()->create();

        $this->gate->evaluate($session, ['query' => 'браслет']);

        $this->assertSame(1, $session->fresh()?->clarification_state['rounds']);
    }

    // ── gate stays shut ───────────────────────────────────────────────────────

    public function test_specific_query_skips_the_probe_entirely(): void
    {
        $this->client->shouldNotReceive('search');

        $session = ChatSession::factory()->create();

        $decision = $this->gate->evaluate($session, ['query' => 'браслет кобра фастекс']);

        $this->assertFalse($decision->needsClarification);
        $this->assertFalse($decision->diversify);
    }

    public function test_a_stated_budget_skips_the_probe(): void
    {
        $this->client->shouldNotReceive('search');

        $session = ChatSession::factory()->create();

        $decision = $this->gate->evaluate($session, ['query' => 'браслет', 'price_max' => 300]);

        $this->assertFalse($decision->needsClarification);
    }

    public function test_narrow_result_set_is_answered_directly(): void
    {
        $this->probeReturns(totalHits: 5);

        $session = ChatSession::factory()->create();

        $decision = $this->gate->evaluate($session, ['query' => 'браслет']);

        $this->assertFalse($decision->needsClarification);
        $this->assertFalse($decision->diversify);
    }

    public function test_disabled_setting_restores_previous_behaviour(): void
    {
        $this->settings->clarificationEnabled = false;
        $this->client->shouldNotReceive('search');

        $session = ChatSession::factory()->create();

        $this->assertFalse($this->gate->evaluate($session, ['query' => 'браслет'])->needsClarification);
    }

    // ── rounds ────────────────────────────────────────────────────────────────

    public function test_second_broad_query_on_the_same_topic_diversifies_instead_of_asking(): void
    {
        $this->client->shouldNotReceive('search');

        $session = ChatSession::factory()->create([
            'clarification_state' => ['rounds' => 1, 'opted_out' => false, 'last_query_terms' => ['браслет']],
        ]);

        $decision = $this->gate->evaluate($session, ['query' => 'браслет']);

        $this->assertFalse($decision->needsClarification);
        $this->assertTrue($decision->diversify);
    }

    public function test_a_new_topic_gets_its_own_round(): void
    {
        $this->probeReturns(totalHits: 52);

        $session = ChatSession::factory()->create([
            'clarification_state' => ['rounds' => 1, 'opted_out' => false, 'last_query_terms' => ['браслет']],
        ]);

        $decision = $this->gate->evaluate($session, ['query' => 'брелок']);

        $this->assertTrue($decision->needsClarification);
        $this->assertSame(1, $decision->round);
    }

    // ── opt-out ───────────────────────────────────────────────────────────────

    public function test_skip_clarification_argument_opts_out(): void
    {
        $this->client->shouldNotReceive('search');

        $session = ChatSession::factory()->create();

        $decision = $this->gate->evaluate($session, ['query' => 'браслет', 'skip_clarification' => true]);

        $this->assertFalse($decision->needsClarification);
        $this->assertTrue($session->fresh()?->clarification_state['opted_out']);
    }

    public function test_customers_own_words_opt_out_even_when_the_model_does_not_pass_the_flag(): void
    {
        $this->client->shouldNotReceive('search');

        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role'       => 'user',
            'content'    => 'Та неважливо, покажи що є',
        ]);

        $decision = $this->gate->evaluate($session, ['query' => 'браслет']);

        $this->assertFalse($decision->needsClarification);
        $this->assertTrue($session->fresh()?->clarification_state['opted_out']);
    }

    public function test_opt_out_survives_for_the_rest_of_the_session(): void
    {
        $this->client->shouldNotReceive('search');

        $session = ChatSession::factory()->create([
            'clarification_state' => ['rounds' => 0, 'opted_out' => true, 'last_query_terms' => []],
        ]);

        $this->assertFalse($this->gate->evaluate($session, ['query' => 'паракорд'])->needsClarification);
    }

    // ── failure handling ──────────────────────────────────────────────────────

    public function test_a_failing_probe_does_not_block_the_answer(): void
    {
        $this->client->expects('search')->andThrow(new RuntimeException('opensearch down'));

        $session = ChatSession::factory()->create();

        $decision = $this->gate->evaluate($session, ['query' => 'браслет']);

        $this->assertFalse($decision->needsClarification);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function probeReturns(int $totalHits): void
    {
        $this->client->expects('search')->andReturn([
            'hits'         => ['hits' => [['_source' => ['name' => 'Браслет "Кобра"']]]],
            'aggregations' => [
                'unique_products' => ['value' => $totalHits],
                'price_ranges'    => ['buckets' => [
                    ['to' => 700, 'products' => ['value' => $totalHits]],
                ]],
                'price_stats' => ['count' => $totalHits, 'min' => 60, 'max' => 420, 'avg' => 175],
            ],
        ]);
    }
}
