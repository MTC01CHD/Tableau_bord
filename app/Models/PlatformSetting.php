<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Settings clé/valeur scopés par tenant (PK composite tenant_id+key).
 *
 * On n'utilise pas Eloquent ici à cause de la PK composite — toutes les
 * opérations passent par DB::table et tenant_id est lu depuis TenantContext.
 */
class PlatformSetting
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $tenantId = app(TenantContext::class)->requireId();
        $row = DB::table('platform_settings')
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first(['value']);
        if (!$row) return $default;
        $v = json_decode((string) $row->value, true);
        // Pour les scalaires (URL, clé, host…), value est stockée comme ['value' => ...]
        return is_array($v) && array_key_exists('value', $v) && count($v) === 1 ? $v['value'] : $v;
    }

    public static function set(string $key, mixed $value): void
    {
        $tenantId = app(TenantContext::class)->requireId();
        $stored = is_scalar($value) || $value === null ? ['value' => $value] : $value;
        DB::table('platform_settings')->upsert(
            [[
                'tenant_id'  => $tenantId,
                'key'        => $key,
                'value'      => json_encode($stored, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['tenant_id', 'key'],
            ['value', 'updated_at']
        );
    }

    public static function bulk(array $keys): array
    {
        return collect($keys)->mapWithKeys(fn ($k) => [$k => self::get($k)])->all();
    }
}
