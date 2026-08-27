<?php

declare(strict_types=1);

namespace App\Console\Commands\Chat;

use App\Services\Chat\Search\IndexSchemas;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OpenSearch\Client;
use Throwable;

#[Signature('chat:setup-search-indices {--f|force : Delete and recreate existing indexes.} {--pipeline-only : Recreate only the hybrid search pipeline, leaving indexed data untouched.}')]
#[Description('Create (or recreate) OpenSearch indexes and the hybrid search pipeline.')]
final class SetupSearchIndices extends Command
{
    public function __construct(private readonly Client $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if ($this->option('pipeline-only')) {
            $this->setupPipeline(force: true);

            $this->newLine();
            $this->info('Done.');

            return self::SUCCESS;
        }

        $this->setupIndex(
            name: (string) config('opensearch.indices.kb'),
            label: 'kb_index',
            schema: IndexSchemas::kb(),
            force: $force,
        );

        $this->setupIndex(
            name: (string) config('opensearch.indices.products'),
            label: 'products_index',
            schema: IndexSchemas::products(),
            force: $force,
        );

        $this->setupPipeline($force);

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $schema */
    private function setupIndex(string $name, string $label, array $schema, bool $force): void
    {
        $exists = $this->client->indices()->exists(['index' => $name]);

        if ($exists && ! $force) {
            $this->line("  <fg=yellow>skip</> {$label} <fg=gray>({$name} already exists, use --force to recreate)</>", verbosity: 'normal');

            return;
        }

        if ($exists && $force) {
            $this->line("  <fg=red>delete</> {$label} <fg=gray>({$name})</>");
            $this->client->indices()->delete(['index' => $name]);
        }

        $this->line("  <fg=green>create</> {$label} <fg=gray>({$name})</>");
        $this->client->indices()->create([
            'index' => $name,
            'body'  => $schema,
        ]);
    }

    private function setupPipeline(bool $force): void
    {
        $id = (string) config('opensearch.hybrid.pipeline_id');

        try {
            $exists = $this->pipelineExists($id);

            if ($exists && ! $force) {
                $this->line("  <fg=yellow>skip</> search pipeline <fg=gray>({$id} already exists, use --force to recreate)</>");

                return;
            }

            if ($exists && $force) {
                $this->line("  <fg=red>delete</> search pipeline <fg=gray>({$id})</>");
                $this->client->searchPipeline()->delete(['id' => $id]);
            }

            $this->line("  <fg=green>create</> search pipeline <fg=gray>({$id})</>");
            $this->client->searchPipeline()->put([
                'id'   => $id,
                'body' => IndexSchemas::hybridPipeline(),
            ]);
        } catch (Throwable $e) {
            // Pipeline creation is optional — fall back to app-side RRF in HybridSearcher.
            $this->warn("  Pipeline setup skipped: {$e->getMessage()}");
            $this->warn('  HybridSearcher will use app-side RRF as fallback.');
        }
    }

    private function pipelineExists(string $id): bool
    {
        try {
            $this->client->searchPipeline()->get(['id' => $id]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
