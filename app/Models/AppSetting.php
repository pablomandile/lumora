<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Ajustes editables por el admin (clave/valor). Cada valor se cachea y la
 * caché se limpia al guardar.
 *
 * @property string $key
 * @property string|null $value
 */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = Cache::rememberForever(
            self::cacheKey($key),
            fn () => static::query()->whereKey($key)->value('value') ?? '',
        );

        return $value === '' ? $default : $value;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($key));
    }

    public static function defaultDailyQuota(): int
    {
        return (int) static::get('default_daily_quota', (string) config('lumora.default_daily_quota'));
    }

    private static function cacheKey(string $key): string
    {
        return "app_setting:{$key}";
    }
}
