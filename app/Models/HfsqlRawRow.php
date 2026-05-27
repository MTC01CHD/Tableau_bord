<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HfsqlRawRow extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'table_name', 'row_key', 'payload', 'synced_at',
    ];

    protected $casts = [
        'payload'   => 'array',
        'synced_at' => 'datetime',
    ];
}
