<?php

declare(strict_types=1);

namespace App\Console\Commands\Search;

use App\Services\Search\Contracts\AdapterInterface;
use App\Services\Search\Engines\Opensearch\OpensearchAdapter;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use OpenSearch\Client;
use RuntimeException;
use Throwable;

final class Setup extends Command
{
    protected $signature = 'search:setup';

    protected $description = 'Setup settings for search';

    /**
     * @param OpensearchAdapter $opensearch
     * @return void
     */
    public function handle(AdapterInterface $opensearch): void
    {
        /** @var Client $client */
        $client = $opensearch->client;

        $this->setSettings($client);

        try {
            $modelGroupId = $this->registerModelGroup($client);
            $this->info('Model group registered. Model group ID: ' . $modelGroupId);

            $connectorId = $this->createConnector($client);
            $this->info('Connector created. Connector ID: ' . $connectorId);

            $registerResponse = $this->registerModel($client, $modelGroupId, $connectorId);
            $registerTask = $this->waitForTaskCompletion($client, $registerResponse['task_id']);
            $modelId = $registerTask['model_id'];
            $this->info('Model registered. Model ID: ' . $modelId);


            $this->createIngestPipeline($client, $modelId);
            $this->info('Pipeline for indexing created');
            $this->createSearchPipeline($client);

            $this->saveModelId($modelId);
            $this->info('Pipeline for search created');
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    private function setSettings(Client $client): void
    {
        $client->cluster()->putSettings([
            'body' => [
                'persistent' => [
                    'plugins.ml_commons.trusted_connector_endpoints_regex' => ['^http://vector-api-opencart:8000/.*$'],
                    'plugins.ml_commons.connector.private_ip_enabled' => true,
                ]
            ],
        ]);
    }

    private function registerModelGroup(Client $client): string
    {
        $modelGroupResponse = $client->ml()->registerModelGroup([
            'body' => [
                'name' => 'remote_model_group',
                'description' => 'Model group for remote models',
            ],
        ]);

        if ($modelGroupResponse['status'] !== 'CREATED') {
            throw new Exception('Model group registration failed. Response: ' . json_encode($modelGroupResponse));
        }

        return $modelGroupResponse['model_group_id'];
    }

    private function createConnector(Client $client): string
    {
        $connectorResponse = $client->ml()->createConnector([
            'body' => [
                'name' => 'External Vectorizer Service',
                'description' => 'Connector to the custom vectorization API',
                'version' => '1',
                'protocol' => 'http',
                'parameters' => [
                    'content_type' => 'application/json',
                ],
                'credential' => [
                    'api_key' => config('api.token'),
                ],
                'actions' => [
                    [
                        'action_type' => 'predict',
                        'method' => 'POST',
                        'url' => config('services.search.embedder_url'),
                        'headers' => [
                            'Authorization' => 'Bearer ${credential.api_key}',
                        ],
                        'request_body' => '{"text": ${parameters.input}}',
                        'post_process_function' => 'connector.post_process.default.embedding'
                    ],
                ],
            ],
        ]);

        return $connectorResponse['connector_id'];
    }

    /**
     * @param Client $client
     * @param string $modelGroupId
     * @param string $connectorId
     * @return array<string, mixed>
     */
    private function registerModel(Client $client, string $modelGroupId, string $connectorId): array
    {
        return $client->ml()->registerModel([
            'body' => [
                'name' => 'sentence-transformers',
                'version' => '1.0.0',
                'description' => 'Wrapper for the multilingual MiniLM model',
                'function_name' => 'remote',
                'model_group_id' => $modelGroupId,
                'connector_id' => $connectorId,
            ]
        ]);
    }

    /**
     * @param Client $client
     * @param string $taskId
     * @return array<string, string>
     * @throws Exception
     */
    private function waitForTaskCompletion(Client $client, string $taskId): array
    {
        $this->info('Waiting for task completion...');

        while (true) {
            sleep(3);

            $taskResponse = $client->ml()->getTask(['id' => $taskId]);

            if (!isset($taskResponse['state'])) {
                throw new Exception('Task state not found. Reesponse: ' . json_encode($taskResponse));
            }

            if ($taskResponse['state'] === 'COMPLETED') {
                $this->info('Task completed');

                return $taskResponse;
            }

            if (in_array($taskResponse['state'], ['FAILED', 'CANCELLED'])) {
                $error = $taskResponse['error'] ?? 'Unknown error';

                throw new Exception('Task failed. Error: ' . $error);
            }
        }
    }

    private function createIngestPipeline(Client $client, string $modelId): void
    {
        $client->ingest()->putPipeline([
            'id' => 'text-pipeline',
            'body' => [
                'description' => 'A pipeline to generate embeddings for product names',
                'processors' => [
                    [
                        'text_embedding' => [
                            'model_id' => $modelId,
                            'field_map' => [
                                'name_ru' => 'name_vector',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function createSearchPipeline(Client $client): void
    {
        $client->searchPipeline()->put([
            'id' => 'hybrid-search-pipeline',
            'body' => [
                'description' => 'A pipeline to combine search and embedding results',
                'response_processors' => [
                    [
                        'collapse' => [
                            'field' => 'product_id'
                        ],
                    ]
                ],
            ],
        ]);
    }

    private function saveModelId(string $modelId): void
    {
        $envFile = base_path('.env');

        if (!File::exists($envFile)) {
            throw new RuntimeException('.env file not found!');
        }

        $envContent = File::get($envFile);
        $key = 'OPENSEARCH_MODEL_ID';
        $newLine = "{$key}={$modelId}";

        if (preg_match("/^{$key}=.*/m", $envContent)) {
            $envContent = preg_replace("/^{$key}=.*/m", $newLine, $envContent);
        } else {
            $envContent = rtrim($envContent, "\n") . "\n{$newLine}\n";
        }

        if ($envContent !== null) {
            File::put($envFile, $envContent);
        }
    }
}
