<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\RetrievalServiceInterface;
use App\Services\Chat\DTO\RetrievedFragment;
use App\Services\Chat\Tools\SearchKnowledgeBaseTool;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class SearchKnowledgeBaseToolTest extends TestCase
{
    private MockInterface $retrieval;

    private SearchKnowledgeBaseTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->retrieval = Mockery::mock(RetrievalServiceInterface::class);

        /** @var RetrievalServiceInterface $service */
        $service = $this->retrieval;

        $this->tool = new SearchKnowledgeBaseTool($service);
    }

    // ── contract ──────────────────────────────────────────────────────────────

    public function test_name_is_search_knowledge_base(): void
    {
        $this->assertSame('search_knowledge_base', $this->tool->getName());
    }

    public function test_schema_requires_query(): void
    {
        $schema = $this->tool->getParameterSchema();

        $this->assertContains('query', $schema['required']);
    }

    public function test_schema_marks_top_k_optional(): void
    {
        $schema = $this->tool->getParameterSchema();

        $this->assertNotContains('top_k', $schema['required']);
    }

    /**
     * The knowledge base holds both Russian and Ukrainian articles and the query
     * language is never passed, so the model must not be able to narrow the search
     * to one language and hide the other half of the corpus.
     */
    public function test_schema_exposes_no_language_parameter(): void
    {
        $schema = $this->tool->getParameterSchema();

        $this->assertArrayNotHasKey('lang', $schema['properties']);
    }

    // ── execute: no results ───────────────────────────────────────────────────

    public function test_execute_returns_found_false_when_no_fragments(): void
    {
        $this->retrieval
            ->expects('retrieveKb')
            ->with('доставка', 5)
            ->andReturn([]);

        $result = json_decode(
            $this->tool->execute(['query' => 'доставка'], $this->makeSession('ru')),
            true,
        );

        $this->assertFalse($result['found']);
        $this->assertEmpty($result['results']);
    }

    // ── execute: with results ─────────────────────────────────────────────────

    public function test_execute_returns_found_true_with_mapped_results(): void
    {
        $fragment = new RetrievedFragment(
            source: 'kb',
            id: '7_0',
            content: 'Доставка по Украине занимает 2–3 дня.',
            score: 0.92,
            metadata: ['title' => 'Доставка'],
        );

        $this->retrieval
            ->expects('retrieveKb')
            ->andReturn([$fragment]);

        $result = json_decode(
            $this->tool->execute(['query' => 'доставка'], $this->makeSession('ru')),
            true,
        );

        $this->assertTrue($result['found']);
        $this->assertCount(1, $result['results']);

        $item = $result['results'][0];
        $this->assertSame('7_0', $item['id']);
        $this->assertSame('Доставка', $item['title']);
        $this->assertSame('Доставка по Украине занимает 2–3 дня.', $item['snippet']);
        $this->assertSame(0.92, $item['score']);
    }

    // ── execute: language never narrows the search ────────────────────────────

    public function test_execute_ignores_session_language(): void
    {
        $this->retrieval
            ->expects('retrieveKb')
            ->with('оплата', 5)
            ->andReturn([]);

        $this->tool->execute(['query' => 'оплата'], $this->makeSession('uk'));
    }

    /** A Russian visitor must still reach Ukrainian articles. */
    public function test_execute_ignores_a_language_argument_from_the_model(): void
    {
        $this->retrieval
            ->expects('retrieveKb')
            ->with('оплата', 5)
            ->andReturn([]);

        $this->tool->execute(['query' => 'оплата', 'lang' => 'ru'], $this->makeSession('uk'));
    }

    // ── execute: custom top_k ─────────────────────────────────────────────────

    public function test_execute_passes_custom_top_k(): void
    {
        $this->retrieval
            ->expects('retrieveKb')
            ->with('гарантия', 3)
            ->andReturn([]);

        $this->tool->execute(['query' => 'гарантия', 'top_k' => 3], $this->makeSession('ru'));
    }

    // ── execute: snippet truncation ───────────────────────────────────────────

    public function test_execute_truncates_long_content_to_300_chars(): void
    {
        $longContent = str_repeat('а', 400);

        $fragment = new RetrievedFragment(
            source: 'kb',
            id: '1_0',
            content: $longContent,
            score: 0.8,
            metadata: ['title' => 'Long article'],
        );

        $this->retrieval
            ->expects('retrieveKb')
            ->andReturn([$fragment]);

        $result = json_decode(
            $this->tool->execute(['query' => 'test'], $this->makeSession('ru')),
            true,
        );

        $snippet = $result['results'][0]['snippet'];
        $this->assertLessThanOrEqual(300, mb_strlen($snippet));
        $this->assertStringEndsWith('...', $snippet);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeSession(string $lang = 'ru'): ChatSession
    {
        return ChatSession::factory()->make(['language' => $lang]);
    }
}
