<?php

declare(strict_types=1);

namespace Tests\Feature\Unit\Chat\Tools;

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

    public function test_schema_marks_lang_and_top_k_optional(): void
    {
        $schema = $this->tool->getParameterSchema();

        $this->assertNotContains('lang', $schema['required']);
        $this->assertNotContains('top_k', $schema['required']);
    }

    public function test_schema_lang_enum_contains_ru_and_uk(): void
    {
        $schema = $this->tool->getParameterSchema();

        $this->assertSame(['ru', 'uk'], $schema['properties']['lang']['enum']);
    }

    // ── execute: no results ───────────────────────────────────────────────────

    public function test_execute_returns_found_false_when_no_fragments(): void
    {
        $this->retrieval
            ->expects('retrieveKb')
            ->with('доставка', 'ru', 5)
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

    // ── execute: uses session language by default ─────────────────────────────

    public function test_execute_uses_session_language_when_lang_not_provided(): void
    {
        $this->retrieval
            ->expects('retrieveKb')
            ->with('оплата', 'uk', 5)
            ->andReturn([]);

        $this->tool->execute(['query' => 'оплата'], $this->makeSession('uk'));
    }

    // ── execute: explicit lang overrides session ──────────────────────────────

    public function test_execute_uses_explicit_lang_over_session_language(): void
    {
        $this->retrieval
            ->expects('retrieveKb')
            ->with('оплата', 'ru', 5)
            ->andReturn([]);

        $this->tool->execute(['query' => 'оплата', 'lang' => 'ru'], $this->makeSession('uk'));
    }

    // ── execute: custom top_k ─────────────────────────────────────────────────

    public function test_execute_passes_custom_top_k(): void
    {
        $this->retrieval
            ->expects('retrieveKb')
            ->with('гарантия', 'ru', 3)
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
