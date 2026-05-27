<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HfsqlRawRow extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'table_name', 'row_key', 'payload', 'synced_at',
    ];

    protected $casts = [
        'payload'   => 'array',
        'synced_at' => 'datetime',
    ];
}
