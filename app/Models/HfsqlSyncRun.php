<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HfsqlSyncRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'table_name', 'started_at', 'finished_at',
        'rows_pulled', 'rows_upserted', 'status', 'error',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
