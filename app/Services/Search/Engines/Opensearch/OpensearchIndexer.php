<?php

declare(strict_types=1);

namespace App\Services\Search\Engines\Opensearch;

use App\Services\Search\Contracts\Indexer;
use App\Services\Search\Contracts\StopWord;
use App\Services\Search\Exceptions\IndexNotFoundException;
use App\Services\Search\Schema\Schema;
use App\Services\Search\Schema\SchemaConfig;
use OpenSearch\Client;

final readonly class OpensearchIndexer implements Indexer, StopWord
{
    public function __construct(
        private Client $client,
        private Schema $schema
    ) {
    }

    public function createIndex(string $indexName): void
    {
        $schemaConfig = $this->getSchemaConfig($indexName);

        $this->client->indices()->create([
            'index' => $indexName,
            'body' => [
                'settings' => [
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                    'analysis' => [
                        'char_filter' => $this->getCharFilter(),
                        'filter' => $this->getFilters(),
                        'analyzer' => $this->getAnalyzer(),
                    ],
                    'index.knn' => true,
                    'default_pipeline' => 'text-pipeline',
                ],
                'mappings' => [
                    'properties' => $this->getFields($schemaConfig),
                ],
            ],
        ]);
    }

    public function deleteIndex(string $indexName): void
    {
        $this->client->indices()->delete([
            'index' => $indexName,
        ]);
    }

    public function bulkAdd(string $indexName, array $documents): void
    {
        $body = [];

        foreach ($documents as $document) {
            $body[] = [
                'index' => [
                    '_index' => $indexName,
                    '_id' => $document[$this->getPrimaryKey($indexName)] ?? '',
                ]
            ];

            $body[] = $document;
        }

        $this->client->bulk([
            'index' => $indexName,
            'body' => $body,
        ]);
    }

    public function bulkUpsert(string $indexName, array $documents): void
    {
        $this->bulkAdd($indexName, $documents);
    }

    public function addDocument(string $indexName, array $document): void
    {
        $this->client->create([
            'index' => $indexName,
            'id' => (string) ($document[$this->getPrimaryKey($indexName)] ?? ''),
            'body' => $document,
        ]);
    }

    public function updateDocument(string $indexName, array $document): void
    {
        $this->client->update([
            'index' => $indexName,
            'id' => (string) ($document[$this->getPrimaryKey($indexName)] ?? ''),
            'body' => [
                'doc' => $document,
            ],
        ]);
    }

    public function delete(string $indexName, string $id): void
    {
        $this->client->delete([
            'index' => $indexName,
            'id' => $id,
        ]);
    }

    /**
     * Refactor this
     * @param List<string> $stopWords
     * @param array<string, string> $options
     * @return void
     */
    public function addStopWords(array $stopWords, array $options): void
    {
    }

    /**
     * @throws IndexNotFoundException
     */
    private function getSchemaConfig(string $indexName): SchemaConfig
    {
        return $this->schema->schemas[$indexName] ?? throw new IndexNotFoundException('Index not found');
    }

    /**
     * @return array<string, mixed>
     */
    private function getCharFilter(): array
    {
        return [
            'keyboard_layout_filter' => [
                'type' => 'mapping',
                'mappings' => [
                    'q=>й', 'w=>ц', 'e=>у', 'r=>к', 't=>е', 'y=>н', 'u=>г', 'i=>ш',
                    'o=>щ', 'p=>з', '[=>х', ']=>ъ', 'a=>ф', 's=>ы', 'd=>в', 'f=>а',
                    'g=>п', 'h=>р', 'j=>о', 'k=>л', 'l=>д', ';=>ж', '\'=>э', 'z=>я',
                    'x=>ч', 'c=>с', 'v=>м', 'b=>и', 'n=>т', 'm=>ь', ',=>б', '.=>ю',
                    'Q=>Й', 'W=>Ц', 'E=>У', 'R=>К', 'T=>Е', 'Y=>Н', 'U=>Г', 'I=>Ш',
                    'O=>Щ', 'P=>З', '{=>Х', '}=>Ъ', 'A=>Ф', 'S=>Ы', 'D=>В', 'F=>А',
                    'G=>П', 'H=>Р', 'J=>О', 'K=>Л', 'L=>Д', ':=>Ж', '"=>Э', 'Z=>Я',
                    'X=>Ч', 'C=>С', 'V=>М', 'B=>И', 'N=>Т', 'M=>Ь', '<=>Б', '>=>Ю'
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getFilters(): array
    {
        return [
            'phonetic_filter' => [
                'type' => 'phonetic',
                'encoder' => 'double_metaphone',
                'replace' => false
            ],
            'russian_morphology' => [
                'type' => 'hunspell',
                'locale' => 'ru_RU',
            ],
            'ukrainian_morphology' => [
                'type' => 'hunspell',
                'locale' => 'uk_UA',
            ],
            'russian_stop' => [
                'type' => 'stop',
                'stopwords' => '_russian_',
            ],
            'ukrainian_stop' => [
                'type' => 'stop',
                'stopwords' => ["а", "але", "ані", "в", "вже", "для", "до", "з", "за", "і", "із", "к", "на", "не", "о", "об", "по", "та", "то", "у", "це", "я"],
            ],
            'edge_ngram_filter' => [
                'type' => 'edge_ngram',
                'min_gram' => 2,
                'max_gram' => 20
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getAnalyzer(): array
    {
        return [
            'russian_analyzer' => [
                'type' => 'custom',
                'char_filter' => ['keyboard_layout_filter'],
                'tokenizer' => 'standard',
                'filter' => [
                    'lowercase',
                    'russian_stop',
                    'russian_morphology',
                ]
            ],
            'ukrainian_analyzer' => [
                'type' => 'custom',
                'char_filter' => ['keyboard_layout_filter'],
                'tokenizer' => 'standard',
                'filter' => [
                    'lowercase',
                    'ukrainian_stop',
                    'ukrainian_morphology',
                ]
            ],
            'phonetic_analyzer' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => [
                    'lowercase',
                    'phonetic_filter'
                ]
            ],
            'exact_analyzer' => [
                'type' => 'custom',
                'tokenizer' => 'keyword',
                'filter' => ['lowercase']
            ],
            'edge_ngram_analyzer' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => [
                    'lowercase',
                    'edge_ngram_filter'
                ]
            ],
            'autocomplete_search_analyzer' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => ['lowercase']
            ],
        ];
    }

    /**
     * @param SchemaConfig $schemaConfig
     * @return array<string, mixed>
     */
    private function getFields(SchemaConfig $schemaConfig): array
    {
        $fields = [];

        foreach ($schemaConfig->fields as $field) {
            $fields[$field->name] = $field->options;
        }

        $fields['name_vector'] = [
            'type' => 'knn_vector',
            'dimension' => 384,
            'method' => [
                'name' => 'hnsw',
                'space_type' => "cosinesimil",
                'engine' => "faiss"
            ]
        ];

        $fields['name_suggest'] = [
            'type' => 'completion',
        ];

        return $fields;
    }

    private function getPrimaryKey(string $indexName): string
    {
        return (string) ($this->getSchemaConfig($indexName)->primaryKey ?? '');
    }
}
