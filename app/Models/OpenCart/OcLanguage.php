<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

/**
 * @property int $language_id
 * @property string $name
 * @property string $code   e.g. "uk", "ru"
 * @property string $locale
 * @property int $status
 */
final class OcLanguage extends ReadOnlyModel
{
    protected $table = 'language';

    protected $primaryKey = 'language_id';
}
