<?php

declare(strict_types=1);

namespace App\Console\Commands\Search;

use App\Services\Search\Contracts\AdapterInterface;
use Illuminate\Console\Command;
use JsonException;

use function array_merge;

final class CreateIndex extends Command
{
    private const STOP_WORDS_FILES = [
        'stopwords-ru.json',
        'stopwords_ua.json',
    ];

    protected $signature = 'search:create-index';

    protected $description = 'Create a new index';

    public function handle(AdapterInterface $searchManager): void
    {
        $searchManager->getIndexer()->createIndex('products');
        $searchManager->getIndexer()->addStopWords(
            stopWords: $this->getStopWords(),
            options: ['name' => 'stopwords_cyrillic']
        );
    }

    /**
     * @return List<non-empty-string>
     */
    private function getStopWords(): array
    {
        $stopWords = [];

        foreach (self::STOP_WORDS_FILES as $fileName) {
            try {
                $words = array_filter($this->loadStopWordsFromFile($fileName));
            } catch (JsonException) {
                continue;
            }

            if (!empty($words)) {
                $stopWords = array_merge($stopWords, $words);
            }

        }

        return $stopWords;
    }

    /**
     * @return array<int, string>
     * @throws JsonException
     */
    private function loadStopWordsFromFile(string $fileName): array
    {
        $filePath = __DIR__ . '/data/' . $fileName;

        if (!file_exists($filePath)) {
            return [];
        }

        $fileContents = file_get_contents($filePath);

        if ($fileContents === false) {
            return [];
        }

        return json_decode(
            $fileContents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}
