<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

/**
 * OpenCart store configuration (`setting` table).
 *
 * Rows are keyed by `store_id` + `code` + `key`; the default store is `store_id = 0`.
 * Only `config_currency` is read today, to resolve the storefront's display currency.
 *
 * @property int $setting_id
 * @property int $store_id
 * @property string $code
 * @property string $key
 * @property string $value
 */
final class OcSetting extends ReadOnlyModel
{
    protected $table = 'setting';

    protected $primaryKey = 'setting_id';

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
        ];
    }
}
