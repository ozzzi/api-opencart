<?php

declare(strict_types=1);

namespace App\Models\Bot;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property string $content
 * @property string|null $category
 * @property string $lang
 * @property bool $is_published
 * @property Carbon|null $opensearch_indexed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static> published()
 * @method static Builder<static> forLang(string $lang)
 */
final class KnowledgeBaseArticle extends Model
{
    protected $table = 'knowledge_base_articles';

    protected $fillable = [
        'title',
        'content',
        'category',
        'lang',
        'is_published',
        'opensearch_indexed_at',
    ];

    /** @param Builder<static> $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** @param Builder<static> $query */
    public function scopeForLang(Builder $query, string $lang): Builder
    {
        return $query->where('lang', $lang);
    }

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'opensearch_indexed_at' => 'datetime',
        ];
    }
}
