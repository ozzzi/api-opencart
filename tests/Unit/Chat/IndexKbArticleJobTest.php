<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Jobs\IndexKbArticleJob;
use App\Models\Bot\KnowledgeBaseArticle;
use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Search\OpenSearchIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use OpenSearch\Client;
use Tests\TestCase;

final class IndexKbArticleJobTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $embeddingClient;

    private OpenSearchIndexer $indexer;

    private MockInterface $osClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->embeddingClient = Mockery::mock(EmbeddingClientInterface::class);
        $this->embeddingClient->allows('embed')->andReturnUsing(
            fn (array $texts) => array_fill(0, count($texts), [0.1, 0.2, 0.3]),
        );

        $this->osClient = Mockery::mock(Client::class);
        $this->osClient->allows('deleteByQuery')->andReturn([]);
        $this->osClient->allows('index')->andReturn(['result' => 'created']);

        $this->indexer = new OpenSearchIndexer($this->osClient);
    }

    public function test_handle_does_not_redispatch_itself_via_the_observer(): void
    {
        Queue::fake();

        // Creating the article legitimately dispatches one indexing job via the observer.
        $article = KnowledgeBaseArticle::create([
            'title' => 'Delivery FAQ',
            'content' => 'We deliver within 3 days.',
            'category' => 'delivery',
            'lang' => 'ru',
            'is_published' => true,
        ]);

        Queue::assertPushedTimes(IndexKbArticleJob::class, 1);

        // Running the job saves opensearch_indexed_at on the article. If that save fires the
        // observer again, a second job gets queued — an infinite loop in production.
        $this->runJob($article->id);

        Queue::assertPushedTimes(IndexKbArticleJob::class, 1);
    }

    public function test_handle_updates_opensearch_indexed_at(): void
    {
        Queue::fake();

        $article = KnowledgeBaseArticle::create([
            'title' => 'Delivery FAQ',
            'content' => 'We deliver within 3 days.',
            'category' => 'delivery',
            'lang' => 'ru',
            'is_published' => true,
            'opensearch_indexed_at' => null,
        ]);

        $this->runJob($article->id);

        $this->assertNotNull($article->fresh()->opensearch_indexed_at);
    }

    public function test_handle_does_nothing_when_article_not_found(): void
    {
        $this->osClient->shouldNotReceive('deleteByQuery');
        $this->osClient->shouldNotReceive('index');

        $this->runJob(999999);
    }

    private function runJob(int $articleId): void
    {
        $job = new IndexKbArticleJob($articleId);
        $job->handle($this->embeddingClient, $this->indexer);
    }
}
