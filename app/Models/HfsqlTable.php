<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HfsqlTable extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'date_column', 'enabled'];
    protected $casts    = ['enabled' => 'boolean'];
}
