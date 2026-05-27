<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HfsqlTable extends Model
{
    protected $fillable = ['name', 'date_column', 'enabled'];
    protected $casts = ['enabled' => 'boolean'];
}
