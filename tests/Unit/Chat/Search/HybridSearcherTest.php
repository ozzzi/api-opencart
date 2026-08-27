<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Search;

use App\Services\Chat\Search\HybridSearcher;
use Mockery;
use Mockery\MockInterface;
use OpenSearch\Client;
use OpenSearch\Namespaces\SearchPipelineNamespace;
use RuntimeException;
use Tests\TestCase;

final class HybridSearcherTest extends TestCase
{
    private MockInterface $client;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bot.clarification.stop_words', ['хочу']);
        config()->set('opensearch.hybrid.pipeline_id', 'chat_hybrid_pipeline');

        $this->client = Mockery::mock(Client::class);
    }

    // ── BM25 query shape ──────────────────────────────────────────────────────

    /**
     * The regression this class exists for. A single `best_fields` multi_match
     * scores a document by its best field alone: "Темляк «Мумія»", matching
     * "темляк" in its name and "череп" in its description, kept only the name
     * score and therefore ranked level with every other lanyard. Separate
     * clauses make the two contributions add up.
     */
    public function test_each_field_group_scores_separately(): void
    {
        $should = $this->captureBm25()['bool']['should'];

        $groups = array_map(
            static fn (array $clause): array => $clause['multi_match']['fields'] ?? [],
            array_slice($should, 0, 5),
        );

        $this->assertContains('name^3', $groups[0]);
        $this->assertContains('name^3', $groups[1]);
        $this->assertContains('category^2', $groups[2]);
        $this->assertContains('attributes', $groups[3]);
        $this->assertContains('description', $groups[4]);

        // The body clause must not be folded together with the name clause.
        $this->assertNotContains('name^3', $groups[4]);
    }

    public function test_the_name_group_is_boosted_as_a_phrase(): void
    {
        $phrase = $this->captureBm25()['bool']['should'][0]['multi_match'];

        $this->assertSame('phrase', $phrase['type']);
        $this->assertSame(8.0, $phrase['boost']);
        // fuzziness is not accepted alongside a phrase query.
        $this->assertArrayNotHasKey('fuzziness', $phrase);
    }

    public function test_names_are_matched_fuzzily_so_a_typo_still_finds_the_product(): void
    {
        $this->assertSame('AUTO', $this->captureBm25()['bool']['should'][1]['multi_match']['fuzziness']);
    }

    public function test_long_text_is_not_matched_fuzzily(): void
    {
        $body = $this->captureBm25()['bool']['should'][4]['multi_match'];

        $this->assertContains('description', $body['fields']);
        $this->assertArrayNotHasKey('fuzziness', $body);
    }

    /**
     * The clause that lifts a document matching every term of the query above
     * the ones matching only its head noun.
     */
    public function test_a_coverage_clause_requires_every_significant_term(): void
    {
        $should = $this->captureBm25('темляк с черепом')['bool']['should'];
        $coverage = end($should);

        $this->assertSame(6.0, $coverage['bool']['boost']);
        $this->assertSame(
            ['темляк', 'черепом'],
            array_map(
                static fn (array $clause): string => $clause['multi_match']['query'],
                $coverage['bool']['must'],
            ),
        );
        $this->assertSame(
            HybridSearcher::TEXT_FIELDS,
            $coverage['bool']['must'][0]['multi_match']['fields'],
        );
    }

    /**
     * A `must` here would turn "темляк з черепом та паракордом" into an empty
     * result set the moment no single product carries all three. Soft means the
     * worst case is the ranking we had before.
     */
    public function test_the_coverage_clause_never_empties_the_result_set(): void
    {
        $bool = $this->captureBm25('темляк с черепом')['bool'];

        $this->assertSame(1, $bool['minimum_should_match']);
        $this->assertArrayNotHasKey('must', $bool);
    }

    public function test_a_single_term_query_gets_no_coverage_clause(): void
    {
        $should = $this->captureBm25('темляк')['bool']['should'];

        $this->assertCount(5, $should);
    }

    public function test_stop_words_do_not_become_required_terms(): void
    {
        $should = $this->captureBm25('хочу темляк с черепом')['bool']['should'];
        $coverage = end($should);

        $this->assertCount(2, $coverage['bool']['must']);
    }

    public function test_filters_are_applied_without_constraining_the_score(): void
    {
        $filters = [['term' => ['in_stock' => true]]];

        $bool = $this->captureBm25('темляк с черепом', $filters)['bool'];

        $this->assertSame($filters, $bool['filter']);
    }

    // ── RRF fallback ──────────────────────────────────────────────────────────

    /**
     * Unweighted RRF fused the two lists 50/50 whatever the configuration said,
     * so the fallback and the pipeline ranked differently and re-tuning the
     * weights did nothing here.
     */
    public function test_rrf_honours_the_configured_weights(): void
    {
        config()->set('opensearch.hybrid.bm25_weight', 0.9);
        config()->set('opensearch.hybrid.knn_weight', 0.1);

        $searcher = $this->searcherWithoutPipeline();

        $this->client->expects('search')->once()->andReturn($this->hits(['bm25-winner']));
        $this->client->expects('search')->once()->andReturn($this->hits(['knn-winner']));

        $ids = array_column($searcher->search('index', 'темляк с черепом', [0.1], [], 3), '_id');

        $this->assertSame('bm25-winner', $ids[0]);
    }

    public function test_rrf_lets_the_knn_list_win_when_it_is_weighted_higher(): void
    {
        config()->set('opensearch.hybrid.bm25_weight', 0.1);
        config()->set('opensearch.hybrid.knn_weight', 0.9);

        $searcher = $this->searcherWithoutPipeline();

        $this->client->expects('search')->once()->andReturn($this->hits(['bm25-winner']));
        $this->client->expects('search')->once()->andReturn($this->hits(['knn-winner']));

        $ids = array_column($searcher->search('index', 'темляк с черепом', [0.1], [], 3), '_id');

        $this->assertSame('knn-winner', $ids[0]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>> $filters
     * @return array<string, mixed>       The BM25 sub-query of the hybrid request.
     */
    private function captureBm25(string $query = 'темляк с черепом', array $filters = []): array
    {
        $pipeline = Mockery::mock(SearchPipelineNamespace::class);
        $pipeline->allows('get')->andReturn(['chat_hybrid_pipeline' => []]);
        $this->client->allows('searchPipeline')->andReturn($pipeline);

        $captured = [];

        $this->client
            ->expects('search')
            ->with(Mockery::on(function (array $params) use (&$captured): bool {
                $captured = $params;

                return true;
            }))
            ->andReturn(['hits' => ['hits' => []]]);

        /** @var Client $client */
        $client = $this->client;

        (new HybridSearcher($client))->search('index', $query, [0.1, 0.2], $filters, 5);

        return $captured['body']['query']['hybrid']['queries'][0];
    }

    private function searcherWithoutPipeline(): HybridSearcher
    {
        $pipeline = Mockery::mock(SearchPipelineNamespace::class);
        $pipeline->allows('get')->andThrow(new RuntimeException('no such pipeline'));
        $this->client->allows('searchPipeline')->andReturn($pipeline);

        /** @var Client $client */
        $client = $this->client;

        return new HybridSearcher($client);
    }

    /**
     * @param  list<string>        $ids
     * @return array<string, mixed>
     */
    private function hits(array $ids): array
    {
        return ['hits' => ['hits' => array_map(
            static fn (string $id): array => ['_id' => $id, '_score' => 1.0, '_source' => []],
            $ids,
        )]];
    }
}
