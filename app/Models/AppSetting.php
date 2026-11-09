<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

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

    public static function retentionDays(): int
    {
        return (int) static::get('retention_days', (string) config('lumora.retention_days'));
    }

    /** URL del gateway: la de Ajustes si hay, si no la del .env. */
    public static function gpuUrl(): ?string
    {
        return static::get('gpu_url') ?? config('services.lumora_gpu.url');
    }

    /** Token del gateway: el de Ajustes (guardado encriptado) si hay, si no el del .env. */
    public static function gpuToken(): ?string
    {
        $stored = static::get('gpu_token');

        if ($stored !== null) {
            try {
                return Crypt::decryptString($stored);
            } catch (DecryptException) {
                // Cambió APP_KEY: se ignora y se usa el del .env.
            }
        }

        return config('services.lumora_gpu.token');
    }

    public static function setGpuToken(?string $token): void
    {
        static::set('gpu_token', filled($token) ? Crypt::encryptString($token) : null);
    }

    private static function cacheKey(string $key): string
    {
        return "app_setting:{$key}";
    }
}
