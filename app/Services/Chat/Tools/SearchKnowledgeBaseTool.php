<?php

declare(strict_types=1);

namespace App\Services\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\RetrievalServiceInterface;
use App\Services\Chat\Tools\Contracts\ToolInterface;

final class SearchKnowledgeBaseTool implements ToolInterface
{
    public function __construct(
        private readonly RetrievalServiceInterface $retrieval,
    ) {
    }

    public function getName(): string
    {
        return 'search_knowledge_base';
    }

    public function getDescription(): string
    {
        return 'Search the store knowledge base (FAQ, policies, delivery, payment, returns, contacts, etc.) '
            .'and return the most relevant fragments. Use this tool whenever the user asks about store policies '
            .'or operational information.';
    }

    /** @return array<string, mixed> */
    public function getParameterSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'The user question or search query.',
                ],
                'lang' => [
                    'type'        => 'string',
                    'enum'        => ['ru', 'uk'],
                    'description' => 'Language of the knowledge base to search. Defaults to "ru".',
                ],
                'top_k' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 10,
                    'description' => 'Maximum number of fragments to return. Defaults to 5.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments, ChatSession $session): string
    {
        $query = (string) $arguments['query'];
        $lang  = isset($arguments['lang']) ? (string) $arguments['lang'] : $session->language ?? 'ru';
        $topK  = isset($arguments['top_k']) ? (int) $arguments['top_k'] : 5;

        $fragments = $this->retrieval->retrieveKb($query, $lang, $topK);

        if ($fragments === []) {
            return json_encode(['results' => [], 'found' => false], JSON_UNESCAPED_UNICODE);
        }

        $results = array_map(static function ($fragment): array {
            $title   = (string) ($fragment->metadata['title'] ?? '');
            $content = $fragment->content;

            // Use the raw content as snippet; trim to 300 chars if needed
            $snippet = mb_strlen($content) > 300
                ? mb_substr($content, 0, 297).'...'
                : $content;

            return [
                'id'      => $fragment->id,
                'title'   => $title,
                'snippet' => $snippet,
                'score'   => round($fragment->score, 4),
            ];
        }, $fragments);

        return json_encode(
            ['results' => $results, 'found' => true],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
