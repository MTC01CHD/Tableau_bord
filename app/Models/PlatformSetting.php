<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $primaryKey = 'key';
    public    $incrementing = false;
    protected $keyType      = 'string';
    protected $fillable     = ['key', 'value'];
    protected $casts        = ['value' => 'array'];

    /** Retourne la valeur ou le défaut, en castant en string. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);
        if (!$row) return $default;
        // Pour les scalaires (URL, clé, host…), value est stockée comme ['value' => ...]
        $v = $row->value;
        return is_array($v) && array_key_exists('value', $v) && count($v) === 1 ? $v['value'] : $v;
    }

    public static function set(string $key, mixed $value): void
    {
        $stored = is_scalar($value) || $value === null ? ['value' => $value] : $value;
        static::updateOrCreate(['key' => $key], ['value' => $stored]);
    }

    public static function bulk(array $keys): array
    {
        return collect($keys)->mapWithKeys(fn ($k) => [$k => self::get($k)])->all();
    }
}
