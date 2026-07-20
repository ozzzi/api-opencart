<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Language as LanguageEnum;
use App\Models\OpenCart\ReadOnlyModel;

/**
 * @property int $language_id
 * @property string $name
 * @property string $code
 */
final class Language extends ReadOnlyModel
{
    protected $table = 'language';

    protected $primaryKey = 'language_id ';

    /**
     * @return array<string, int>
     */
    public function initLanguages(): array
    {
        return $this->query()
            ->where('status', 1)
            ->get()
            ->mapWithKeys(function ($language) {
                $key = LanguageEnum::from($language->code)->toLowerCase();

                return [$key => (int) $language->language_id];
            })->toArray();
    }
}
